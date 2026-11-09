<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Выбор фирмы — Prime Mobile OS</title>
    <style>
        body { font-family: Onest, system-ui, sans-serif; background: #F6F7F9; color: #111827; margin: 0; }
        main { max-width: 420px; margin: 12vh auto; padding: 0 16px; }
        h1 { font-size: 24px; margin-bottom: 16px; }
        form { margin: 0 0 8px; }
        button { width: 100%; text-align: left; padding: 16px; border: 1px solid #E5E7EB; border-radius: 12px; background: #fff; font-size: 16px; cursor: pointer; }
        button:hover { border-color: #2A4BD7; }
    </style>
</head>
<body>
<main>
    <h1>Выберите фирму</h1>
    @foreach ($organizations as $organization)
        <form method="post" action="{{ route('organization.switch', $organization->id) }}">
            @csrf
            <button type="submit">{{ $organization->name }}</button>
        </form>
    @endforeach
</main>
</body>
</html>
