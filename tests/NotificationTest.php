<?php

namespace Tests;

use App\Conference;
use App\Notifications\CFPsAreOpen;
use App\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

class NotificationTest extends IntegrationTestCase
{
    /** @test */
    function command_will_trigger_notification_for_approved_and_not_shared_conference()
    {
        Notification::fake();
        $user = User::factory()->wantsNotifications()->create();
        $conference = Conference::factory()->create(['is_approved' => true, 'is_shared' => false]);

        Artisan::call('symposium:notifyCfps');

        $this->assertUserNotifiedOfCfp($user, $conference);
        $this->assertTrue(Conference::first()->is_shared);
    }

    /** @test */
    function command_will_not_trigger_notification_for_unapproved_conference()
    {
        Notification::fake();
        $user = User::factory()->create();
        Conference::factory()->create(['is_approved' => false]);

        Artisan::call('symposium:notifyCfps');

        Notification::assertNotSentTo([$user], CFPsAreOpen::class);
    }

    /** @test */
    function command_will_not_trigger_notification_for_already_shared_conference()
    {
        Notification::fake();
        $user = User::factory()->create();
        Conference::factory()->create([
            'is_approved' => false,
            'is_shared' => false,
        ]);

        Artisan::call('symposium:notifyCfps');

        Notification::assertNotSentTo([$user], CFPsAreOpen::class);
    }

    /** @test */
    function command_will_not_trigger_notification_for_closed_cfp()
    {
        Notification::fake();
        $user = User::factory()->wantsNotifications()->create();
        Conference::factory()->closedCFP()->create(['is_approved' => true]);

        Artisan::call('symposium:notifyCfps');

        Notification::assertNotSentTo([$user], CFPsAreOpen::class);
    }

    /** @test */
    function command_will_not_trigger_notification_if_no_cfp_dates_given()
    {
        Notification::fake();
        $user = User::factory()->wantsNotifications()->create();
        Conference::factory()->noCFPDates()->create(['is_approved' => true]);

        Artisan::call('symposium:notifyCfps');

        Notification::assertNotSentTo([$user], CFPsAreOpen::class);
    }

    /** @test */
    function command_will_not_trigger_notification_for_opt_out_user()
    {
        Notification::fake();
        $user = User::factory()->create();
        Conference::factory()->create(['is_approved' => true]);

        Artisan::call('symposium:notifyCfps');

        Notification::assertNotSentTo([$user], CFPsAreOpen::class);
    }

    function assertUserNotifiedOfCfp($user, $conference)
    {
        Notification::assertSentTo($user, CFPsAreOpen::class, function ($notification) use ($conference) {
            return $notification->conferences->pluck('id')->contains($conference->id);
        });
    }
}
