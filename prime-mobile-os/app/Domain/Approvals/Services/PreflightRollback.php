<?php

namespace App\Domain\Approvals\Services;

use RuntimeException;

/** @internal thrown to roll back the approval preflight (DatabaseApprovalGate::collect()) */
final class PreflightRollback extends RuntimeException {}
