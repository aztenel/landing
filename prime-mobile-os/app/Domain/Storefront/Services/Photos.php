<?php

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Models\SitePhoto;
use App\Domain\Storefront\Support\SiteChange;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * «Сфотографировал — появился» (TZ 05 §3). The original goes to the PRIVATE disk; the public sizes are re-encoded by
 * GD (which writes no metadata: geolocation, camera, date are gone — §3.3 п. 3), turned by EXIF, cut to a square and
 * saved as WebP under random names (no product code, no firm). og (1200×630) is JPEG for messengers.
 */
final class Photos
{
    public const MAX_PER_UNIT = 12;

    public const MAX_BYTES = 25 * 1024 * 1024;

    public const MIN_SIDE = 800;

    public const SIZES = ['xl' => 1600, 'lg' => 1000, 'md' => 600, 'sm' => 300];

    /** @param array{stock_unit_id?: int, model_id?: int, color_id?: int, accessory_id?: int} $owner */
    public function upload(array $owner, UploadedFile $file, User $user): SitePhoto
    {
        $unit = isset($owner['stock_unit_id']) ? StockUnit::query()->findOrFail($owner['stock_unit_id']) : null;
        $scope = SitePhoto::query()->whereNull('deleted_at')->where(fn ($q) => $this->owned($q, $owner));
        if ($scope->count() >= self::MAX_PER_UNIT) {
            throw UserFacingException::because('photo_limit', __('Не больше :n фото', ['n' => self::MAX_PER_UNIT]));
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw UserFacingException::because('photo_big', __('Фото больше 25 МБ'));
        }
        $info = @getimagesize($file->getRealPath());
        $type = $info[2] ?? null;
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw UserFacingException::because('photo_type', __('Нужно фото JPEG, PNG или WebP. На iPhone: Настройки → Камера → Форматы → «Наиболее совместимый»'));
        }
        $org = CurrentOrganization::instance()->requireId();
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$type];
        $original = Storage::disk('local')->putFileAs($org.'/media/originals', $file, Str::uuid().'.'.$ext);

        $listed = $unit && Listing::query()->where('unit_ref', $unit->id)->where('public_status', '!=', 'sold')->exists();
        $photo = SitePhoto::query()->create($owner + [
            'sort' => (int) $scope->max('sort') + 1, 'status' => 'processing', 'original_path' => $original, 'uploaded_by' => $user->id,
            'needs_review' => $listed && ! Access::allows('site.visibility', $user) && SiteSettings::current()->get('photo_review'),
        ]);
        try {
            [$files, $flags] = $this->process(Storage::disk('local')->path($original), $type);
            $photo->update(['status' => 'ready', 'files' => $files, 'flags' => $flags ?: null]);
        } catch (UserFacingException $e) {
            $photo->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Storage::disk('local')->delete($original);
            throw $e;
        }
        activity('site')->causedBy($user)->withProperties(['unit' => $unit?->public_code, 'photo' => $photo->id])->log('photo.added');
        $this->announce($photo);

        return $photo->refresh();
    }

    /** @return array{0: array<string, string>, 1: list<string>} */
    public function process(string $path, int $type): array
    {
        $src = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
        };
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            $src = match ($o) {
                3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => $src
            };
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if (min($w, $h) < self::MIN_SIDE) {
            throw UserFacingException::because('photo_small', __('Фото слишком маленькое (:n px). Снимите ближе или выберите оригинал', ['n' => min($w, $h)]));
        }
        // square 1:1 by the centre (§3.4)
        $side = min($w, $h);
        $square = imagecreatetruecolor($side, $side);
        imagecopy($square, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $side, $side);

        $base = 'site/'.Str::lower(Str::random(24));
        $files = [];
        foreach (self::SIZES as $size => $px) {
            $px = min($px, $side);
            $img = imagecreatetruecolor($px, $px);
            imagecopyresampled($img, $square, 0, 0, 0, 0, $px, $px, $side, $side);
            ob_start();
            imagewebp($img, null, 80);
            Storage::disk('public')->put($files[$size] = $base.'-'.$size.'.webp', ob_get_clean());
        }
        $og = imagecreatetruecolor(1200, 630);
        imagefill($og, 0, 0, imagecolorallocate($og, 0xF3, 0xF4, 0xF6));
        imagecopyresampled($og, $square, 285, 0, 0, 0, 630, 630, $side, $side);
        ob_start();
        imagejpeg($og, null, 82);
        Storage::disk('public')->put($files['og'] = $base.'-og.jpg', ob_get_clean());

        // hints, not blockers (§3.5): dark photo
        $probe = imagecreatetruecolor(16, 16);
        imagecopyresampled($probe, $square, 0, 0, 0, 0, 16, 16, $side, $side);
        $sum = 0;
        for ($x = 0; $x < 16; $x++) {
            for ($y = 0; $y < 16; $y++) {
                $c = imagecolorat($probe, $x, $y);
                $sum += 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            }
        }

        return [$files, $sum / 256 < 60 ? ['dark'] : []];
    }

    public function remove(SitePhoto $photo, User $user): void
    {
        $photo->update(['deleted_at' => now()]);
        activity('site')->causedBy($user)->withProperties(['photo' => $photo->id])->log('photo.removed');
        $this->announce($photo);
    }

    /** Move one place left (-1) / right (+1); «Главное» = first. */
    public function move(SitePhoto $photo, int $dir): void
    {
        $list = SitePhoto::query()->whereNull('deleted_at')->where(fn ($q) => $this->owned($q, $photo->only(['stock_unit_id', 'model_id', 'color_id', 'accessory_id'])))
            ->orderBy('sort')->orderBy('id')->get()->values();
        $i = $list->search(fn ($p) => $p->id === $photo->id);
        $j = $dir === 0 ? 0 : $i + $dir;
        if ($i === false || $j < 0 || $j >= $list->count() || $i === $j) {
            return;
        }
        $item = $list->pull($i);
        $list = $list->values();
        $list->splice($j, 0, [$item]);
        foreach ($list->values() as $n => $p) {
            $p->update(['sort' => $n + 1]);
        }
        $this->announce($photo);
    }

    public function review(SitePhoto $photo, User $user, bool $keep): void
    {
        $photo->update(['needs_review' => false, 'reviewed_by' => $user->id, 'reviewed_at' => now()] + ($keep ? [] : ['deleted_at' => now()]));
        $this->announce($photo);
    }

    /** Nightly: files of photos removed more than 30 days ago (§3.7). */
    public function purge(): int
    {
        $n = 0;
        foreach (SitePhoto::query()->whereNotNull('deleted_at')->where('deleted_at', '<', now()->subDays(30))->whereNotNull('files')->get() as $p) {
            Storage::disk('public')->delete(array_values((array) $p->files));
            $p->original_path && Storage::disk('local')->delete($p->original_path);
            $p->update(['files' => null, 'original_path' => null]);
            $n++;
        }

        return $n;
    }

    private function owned($q, array $owner): void
    {
        foreach (['stock_unit_id', 'model_id', 'color_id', 'accessory_id'] as $k) {
            isset($owner[$k]) ? $q->where($k, $owner[$k]) : $q->whereNull($k);
        }
    }

    private function announce(SitePhoto $photo): void
    {
        if ($photo->stock_unit_id) {
            SiteChange::units([$photo->stock_unit_id], 'photo');
        } elseif ($photo->accessory_id) {
            SiteChange::accessories('photo');
        } else {
            SiteChange::units(StockUnit::query()->whereIn('variant_id', Variant::query()->where('model_id', $photo->model_id)->select('id'))
                ->whereIn('status', ['in_stock', 'reserved', 'ordered', 'sold'])->pluck('id')->all(), 'model photo');
        }
    }
}
