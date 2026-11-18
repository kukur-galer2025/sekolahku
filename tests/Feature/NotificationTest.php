<?php

namespace Tests\Feature;

use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\UserNotification;
use App\Services\Notify\WebPush;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Tahap 4b: lonceng, push tanpa isi, dan pemicu (tugas, informasi). */
class NotificationTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
    }

    private function keys(): void
    {
        $k = WebPush::generateKeys();
        config(['services.webpush.public_key' => $k['public'], 'services.webpush.private_key' => $k['private'], 'services.webpush.subject' => 'mailto:tes@parama.sbs']);
    }

    public function test_tugas_baru_masuk_lonceng_siswa_sekelas(): void
    {
        $this->actingAs($this->s['teacher']->user)->postJson($this->at($this->s['school'], 'api/assignments'), [
            'classroom_id' => $this->s['classroom']->id, 'subject' => 'Matematika', 'title' => 'Latihan bab 2', 'due_date' => now()->addDay()->toDateString(),
        ])->assertCreated();

        $this->actingAs($this->s['student']->user)->getJson($this->at($this->s['school'], 'api/notifications'))->assertOk()
            ->assertJsonPath('unread', 1)->assertJsonPath('items.0.title', 'Tugas baru: Matematika')->assertJsonPath('items.0.url', '/siswa#/tugas');
        $this->actingAs($this->s['teacher']->user)->getJson($this->at($this->s['school'], 'api/notifications'))->assertJsonPath('unread', 0);

        $this->actingAs($this->s['student']->user)->postJson($this->at($this->s['school'], 'api/notifications/read'), [])->assertOk();
        $this->actingAs($this->s['student']->user)->getJson($this->at($this->s['school'], 'api/notifications/latest'))->assertJsonPath('unread', 0)->assertJsonPath('item', null);
    }

    public function test_informasi_untuk_guru_tidak_sampai_ke_siswa(): void
    {
        $this->actingAs($this->s['admin'])->putJson($this->at($this->s['school'], 'api/info-coordinators/kepegawaian'), ['teacher_id' => $this->s['teacher']->id])->assertOk();
        $this->actingAs($this->s['teacher']->user)->postJson($this->at($this->s['school'], 'api/announcements'), ['title' => 'Rapat', 'body' => 'Rapat jam 13.00', 'audience' => 'guru', 'category' => 'kepegawaian'])->assertCreated();

        $this->tenant()->run($this->s['school'], function () {
            $this->assertSame(1, UserNotification::where('user_id', $this->s['teacher']->user->id)->where('type', 'announcement')->count());
            $this->assertSame(1, UserNotification::where('user_id', $this->s['teacher']->user->id)->where('type', 'coordinator')->count()); // ditunjuk koordinator
            $this->assertSame(0, UserNotification::where('user_id', $this->s['student']->user->id)->count());
        });
    }

    public function test_daftar_push_dan_tugas_baru_membangunkan_hp(): void
    {
        $this->keys();
        Queue::fake();

        $this->actingAs($this->s['student']->user)->postJson($this->at($this->s['school'], 'api/push/subscriptions'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'])->assertCreated();
        $this->actingAs($this->s['teacher']->user)->postJson($this->at($this->s['school'], 'api/assignments'), [
            'classroom_id' => $this->s['classroom']->id, 'title' => 'PR', 'due_date' => now()->addDay()->toDateString(),
        ])->assertCreated();

        Queue::assertPushed(SendWebPush::class, 1);
    }

    public function test_push_tanpa_isi_dengan_vapid_dan_langganan_mati_dihapus(): void
    {
        $this->keys();
        $sub = $this->tenant()->run($this->s['school'], fn () => PushSubscription::create([
            'user_id' => $this->s['student']->user->id, 'endpoint_hash' => hash('sha256', 'x'), 'endpoint' => 'https://push.example.com/abc',
        ]));

        Http::fake(['push.example.com/*' => Http::sequence()->push('', 201)->push('', 410)]);
        $this->tenant()->run($this->s['school'], function () use ($sub) {
            $this->assertTrue(app(WebPush::class)->send($sub));
            $this->assertFalse(app(WebPush::class)->send($sub->fresh()));
            $this->assertNull(PushSubscription::find($sub->id));
        });

        Http::assertSent(fn ($r) => str_starts_with($r->header('Authorization')[0] ?? '', 'vapid t=') && $r->body() === '' && $r->header('TTL')[0] === '86400');
    }

    public function test_tanpa_kunci_vapid_lonceng_tetap_jalan(): void
    {
        config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);
        $this->actingAs($this->s['student']->user)->postJson($this->at($this->s['school'], 'api/push/subscriptions'), ['endpoint' => 'https://x.example/1'])->assertStatus(503);
        $this->actingAs($this->s['student']->user)->getJson($this->at($this->s['school'], 'api/notifications'))->assertOk()->assertJsonPath('push.available', false);
    }

    public function test_notifikasi_tersinkron_di_semua_portal(): void
    {
        $api = fn ($p) => $this->at($this->s['school'], 'api/'.$p);
        \App\Models\School::query()->whereKey($this->s['school']->id)->update(['plan' => 'all_service']);
        $this->tenant()->run($this->s['school'], fn () => $this->s['classroom']->update(['homeroom_teacher_id' => $this->s['teacher']->id]));
        $this->tenant()->forget();
        $types = fn ($u) => $this->tenant()->run($this->s['school'], fn () => UserNotification::where('user_id', $u->id)->pluck('type')->all());

        // Agenda sekolah → guru & siswa; agenda briefing → guru saja.
        $this->actingAs($this->s['admin'])->putJson($api('agenda/sekolah'), ['title' => 'Agenda pekan ini', 'body' => 'Senin upacara'])->assertOk();
        $this->actingAs($this->s['admin'])->putJson($api('agenda/briefing'), ['title' => 'Briefing', 'body' => 'Rapat guru'])->assertOk();
        // Wali kelas menetapkan jabatan → siswa diberi tahu.
        $this->actingAs($this->s['teacher']->user)->putJson($api('students/'.$this->s['student']->id.'/class-role'), ['class_role' => 'bendahara_1'])->assertOk();
        // Bacaan baru → siswa.
        $this->actingAs($this->s['teacher']->user)->post($api('library'), ['title' => 'Modul', 'category' => 'Modul Ajar', 'url' => 'https://contoh.id/modul'], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['agenda', 'class_role', 'library'], collect($types($this->s['student']->user))->sort()->values()->all());
        $this->assertSame(['agenda', 'agenda'], $types($this->s['teacher']->user));
        $this->actingAs($this->s['student']->user)->getJson($api('notifications'))->assertJsonPath('items.1.url', '/siswa#/kas'); // bendahara → Kas Kelas

        // Paket tanpa fitur: notifikasinya tidak dikirim (menu terkunci).
        \App\Models\School::query()->whereKey($this->s['school']->id)->update(['plan' => 'starter']);
        $this->tenant()->run($this->s['school']->fresh(), fn () => $this->assertSame(0, app(\App\Services\Notify\Notifier::class)->send([$this->s['student']->user], 'library', 'X')));
        $this->tenant()->run($this->s['school']->fresh(), fn () => $this->assertSame(1, app(\App\Services\Notify\Notifier::class)->send([$this->s['student']->user], 'announcement', 'X')));
    }
}
