<?php

namespace Tests\Feature\Platform;

use App\Models\DataImport;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ScheduleImportTest extends TestCase
{
    public function test_impor_jadwal_rentang_jam_guru_nama_dan_peringatan(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->tenant()->run($s['school'], function () {
            $u = User::factory()->create(['role' => 'teacher', 'username' => '3301010102']);
            Teacher::create(['user_id' => $u->id, 'nik' => '3301010102', 'name' => 'Sari Wulandari, S.Pd.']);
        });

        $path = tempnam(sys_get_temp_dir(), 'j').'.xlsx';
        $w = new Writer; $w->openToFile($path);
        foreach ([
            ['JADWAL PELAJARAN'], [],
            ['HARI', 'JAM KE', 'KELAS', 'MATA PELAJARAN', 'GURU'],
            ['Senin', '1-2', 'X - FKK 1', 'Matematika', '3301010101'],
            ['selasa', '1', 'x-fkk 1', 'Biologi', 'sari wulandari s.pd'],
            ['Selasa', '1', 'X-FKK 1', 'Kimia', 'Pak Entah'],           // bentrok kelas, guru tak dikenal
            ['Rabu', '2', 'XII-TKJ 9', 'Fisika', '3301010101'],          // kelas belum ada
            ['Minggu', '1', 'X-FKK 1', 'Seni', ''],                      // hari tidak dikenali
        ] as $r) { $w->addRow(Row::fromValues($r)); }
        $w->close();

        $this->actingAs($s['admin'])->post($this->at($s['school'], 'impor/schedules'), ['file' => new UploadedFile($path, 'jadwal.xlsx', null, null, true)]);
        $i = $this->tenant()->run($s['school'], fn () => DataImport::latest('id')->first());

        $this->assertTrue($i->preview['ok']);
        $this->assertSame(3, $i->preview['stats']['Blok jadwal']);
        $warn = implode(' | ', $i->preview['warnings']);
        $this->assertStringContainsString('XII-TKJ 9', $warn);
        $this->assertStringContainsString('Pak Entah', $warn);
        $this->assertStringContainsString('Kelas bentrok', $warn);
        $this->assertStringContainsString('1 baris dilewati', $warn);

        $this->actingAs($s['admin'])->post($this->at($s['school'], "impor/{$i->id}/konfirmasi"), ['mode' => 'add']);

        $this->tenant()->run($s['school'], function () use ($s) {
            $mat = Schedule::where('subject', 'Matematika')->first();
            $this->assertSame([1, 1, 2], [$mat->day, $mat->period_start, $mat->period_count]);
            $this->assertSame($s['teacher']->id, $mat->teacher_id);
            $this->assertSame('Sari Wulandari, S.Pd.', Schedule::where('subject', 'Biologi')->first()->teacher->name);
            $this->assertNull(Schedule::where('subject', 'Kimia')->value('teacher_id'));
        });
    }
}
