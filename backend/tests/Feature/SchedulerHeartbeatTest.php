<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
| نبضةُ المجدوِل، والمسارُ الذي يقرؤُه الفحصُ من خارجِ PHP كلِّه.
|
| ⚠️ `restart: unless-stopped` يُعيدُ ما **خرج** ولا يرى ما **علّق**، ولا أمرَ
| حالةٍ لـ`schedule:work` كما لـHorizon. فالنبضةُ هي الإشارةُ الوحيدة، و
| `healthcheck` في `docker-compose.prod.yml` يقرأُ عمرَ الملفِّ باسمِه المكتوبِ
| هناك حرفيّاً.
|
| ⚠️ **وهذا هو خطرُ الهجاءَين بعينِه**: طرفانِ يُسمّيانِ ملفّاً واحداً، أحدُهما
| PHP والآخرُ YAML، ولا مُصرِّفَ يربطُهما. فتغييرُ المسارِ هنا لا يكسرُ شيئاً —
| يجعلُ الحاويةَ معتلّةً إلى الأبدِ بلا سببٍ يظهرُ في أيِّ سجلّ، وهي أسوأُ من
| غيابِ الفحصِ لأنّها تُعلِّمُ الناسَ تجاهلَ الاعتلال.
*/
it('registers a heartbeat that runs every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event->description === 'scheduler-heartbeat');

    expect($events)->toHaveCount(1)
        ->and($events->first()?->expression)->toBe('* * * * *');
});

it('touches the very path the container healthcheck stats', function (): void {
    $compose = file_get_contents(base_path('../docker/docker-compose.prod.yml'));
    $path = storage_path('app/scheduler-heartbeat');

    @unlink($path);

    collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'scheduler-heartbeat')
        ?->run(app());

    expect(file_exists($path))->toBeTrue()
        // المسارُ داخلَ الحاويةِ ثابتٌ، والمشترَكُ بينَ الطرفَينِ هو ذيلُه.
        ->and($compose)->toContain('/var/www/html/storage/app/scheduler-heartbeat');

    @unlink($path);
});
