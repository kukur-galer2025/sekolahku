<?php

namespace App\Models;

use App\Support\SchoolMenu;
use Illuminate\Database\Eloquent\Model;

/** Template menu & istilah buatan super-admin (data pusat). */
class MenuTemplate extends Model
{
    protected $fillable = ['name', 'description', 'data', 'created_by'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /** Isi template yang sudah dibersihkan (format sama dengan schools.settings['menu']). */
    public function menu(): array
    {
        return SchoolMenu::clean((array) $this->data);
    }

    public function changeCount(): int
    {
        $m = $this->menu();

        return array_sum(array_map('count', $m['names'])) + count($m['hidden']);
    }
}
