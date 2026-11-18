<?php

namespace App\Tenancy;

use RuntimeException;

/** Dilempar saat kode mencoba menembus batas antar-sekolah. Selalu bug, bukan input pengguna. */
class TenantViolation extends RuntimeException {}
