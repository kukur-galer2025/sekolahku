<?php

namespace App\Services;

use RuntimeException;

/** Password benar, tetapi akun memakai 2FA: login baru selesai setelah kode authenticator dimasukkan. */
class TwoFactorRequired extends RuntimeException {}
