<?php

namespace Tests;

use App\Conference;
use App\User;
use Carbon\Carbon;

class ConferenceTest extends IntegrationTestCase
{
    /** @test */
    function user_can_create_conference()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->visit('/conferences/create')
            ->type('Das Conf', '#title')
            ->type('A very good conference about things', '#description')
            ->type('http://dasconf.org', '#url')
            ->press('Create');

        $this->seeInDatabase('conferences', [
            'title' => 'Das Conf',
            'description' => 'A very good conference about things',
        ]);
    }

    /** @test */
    function a_conference_can_include_location_coordinates()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/conferences', [
                'title' => 'JediCon',
                'description' => 'The force is strong here',
                'url' => 'https://jedicon.com',
                'latitude' => '37.7991531',
                'longitude' => '-122.45050129999998',
            ]);

        $this->seeInDatabase('conferences', [
            'title' => 'JediCon',
            'description' => 'The force is strong here',
            'url' => 'https://jedicon.com',
            'latitude' => '37.7991531',
            'longitude' => '-122.45050129999998',
        ]);
    }

    /** @test */
    function a_conference_cannot_end_before_it_begins()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/conferences', [
                'title' => 'JediCon',
                'description' => 'The force is strong here',
                'url' => 'https://jedicon.com',
                'starts_at' => Carbon::parse('+3 days')->toDateString(),
                'ends_at' => Carbon::parse('+2 days')->toDateString(),
            ]);

        $this->seeStatusCode(302);
        $this->assertRedirectedTo('/conferences/create');
        $this->dontSeeInDatabase('conferences', [
            'title' => 'JediCon',
        ]);
    }

    /** @test */
    function user_can_edit_conference()
    {
        $this->disableExceptionHandling();

        $user = User::factory()->create();

        $conference = Conference::factory()->create([
            'author_id' => $user->id,
            'title' => 'Rubycon',
            'description' => 'A conference about Ruby',
            'is_approved' => true,
        ]);

        $this->actingAs($user)
            ->visit("/conferences/{$conference->id}/edit")
            ->type('Laracon', '#title')
            ->type('A conference about Laravel', '#description')
            ->press('Update');

        $this->seeInDatabase('conferences', [
            'title' => 'Laracon',
            'description' => 'A conference about Laravel',
        ]);

        $this->missingFromDatabase('conferences', [
            'title' => 'Rubycon',
            'description' => 'A conference about Ruby',
        ]);
    }

    /** @test */
    function a_conference_cannot_be_updated_to_end_before_it_begins()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create([
            'author_id' => $user->id,
            'title' => 'Rubycon',
            'description' => 'A conference about Ruby',
            'is_approved' => true,
            'starts_at' => Carbon::parse('+3 days')->toDateString(),
            'ends_at' => Carbon::parse('+4 days')->toDateString(),
        ]);

        $this->actingAs($user)
            ->visit("/conferences/{$conference->id}/edit")
            ->type(Carbon::parse('+2 days')->toDateString(), '#ends_at')
            ->press('Update');

        $this->assertEquals(
            Carbon::parse('+4 days')->toDateString(),
            $conference->fresh()->ends_at->toDateString(),
        );
    }

    /** @test */
    function conferences_accept_proposals_during_the_call_for_papers()
    {
        $conference = Conference::factory()->create([
            'cfp_starts_at' => Carbon::yesterday(),
            'cfp_ends_at' => Carbon::tomorrow(),
        ]);

        $this->assertTrue($conference->isCurrentlyAcceptingProposals());
    }

    /** @test */
    function conferences_dont_accept_proposals_outside_of_the_call_for_papers()
    {
        $conference = Conference::factory()->create([
            'cfp_starts_at' => Carbon::tomorrow(),
            'cfp_ends_at' => Carbon::tomorrow()->addDay(),
        ]);

        $this->assertFalse($conference->isCurrentlyAcceptingProposals());

        $conference = Conference::factory()->create([
            'cfp_starts_at' => Carbon::yesterday()->subDay(),
            'cfp_ends_at' => Carbon::yesterday(),
        ]);

        $this->assertFalse($conference->isCurrentlyAcceptingProposals());
    }

    /** @test */
    function conferences_that_havent_announced_their_cfp_are_not_accepting_proposals()
    {
        $conference = Conference::factory()->create([
            'cfp_starts_at' => null,
            'cfp_ends_at' => null,
        ]);

        $this->assertFalse($conference->isCurrentlyAcceptingProposals());
    }

    /** @test */
    function non_owners_can_view_conference()
    {
        $user = User::factory()->create();

        $otherUser = User::factory()->create();
        $conference = Conference::factory()->create();
        $otherUser->conferences()->save($conference);

        $this->actingAs($user)
            ->visit("conferences/{$conference->id}")
            ->see($conference->title);
    }

    /** @test */
    function guests_can_view_conference()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create(['is_approved' => true]);
        $user->conferences()
            ->save($conference);

        $this->visit("conferences/{$conference->id}")
            ->see($conference->title);
    }

    /** @test */
    function guests_can_view_conference_list()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create(['is_approved' => true]);
        $user->conferences()
            ->save($conference);

        $this->visit('conferences?filter=all')
            ->seePageIs('conferences?filter=all')
            ->see($conference->title);
    }

    /** @test */
    function guests_cannot_create_conference()
    {
        $this->visit('conferences/create')
            ->seePageIs('login');
    }

    /** @test */
    function it_can_pull_only_approved_conferences()
    {
        Conference::factory()->create();
        Conference::factory()->create(['is_approved' => true]);

        $this->assertEquals(1, Conference::approved()->count());
    }

    /** @test */
    function it_can_pull_only_not_shared_conferences()
    {
        Conference::factory()->create();
        Conference::factory()->create(['is_shared' => true]);

        $this->assertEquals(1, Conference::notShared()->count());
    }

    /** @test */
    function cfp_closing_next_list_sorts_null_cfp_to_the_bottom()
    {
        $nullCfp = Conference::factory()->approved()->create([
            'cfp_starts_at' => null,
            'cfp_ends_at' => null,
        ]);
        $pastCfp = Conference::factory()->approved()->create([
            'cfp_starts_at' => Carbon::yesterday()->subDay(),
            'cfp_ends_at' => Carbon::yesterday(),
        ]);
        $futureCfp = Conference::factory()->approved()->create([
            'cfp_starts_at' => Carbon::yesterday(),
            'cfp_ends_at' => Carbon::tomorrow(),
        ]);

        $this->get('conferences');

        $this->assertConferenceSort([
            $pastCfp,
            $futureCfp,
            $nullCfp,
        ]);
    }

    /** @test */
    function cfp_by_date_list_sorts_by_date()
    {
        $conferenceA = Conference::factory()->approved()->create([
            'starts_at' => Carbon::now()->subDay(),
        ]);
        $conferenceB = Conference::factory()->approved()->create([
            'starts_at' => Carbon::now()->addDay(),
        ]);

        $this->get('conferences?filter=all&sort=date');

        $this->assertConferenceSort([
            $conferenceA,
            $conferenceB,
        ]);
    }

    /** @test */
    function guests_cannot_dismiss_conference()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create();
        $user->conferences()->save($conference);

        $this->visit("conferences/{$conference->id}/dismiss")
            ->seePageIs('login');
    }

    /** @test */
    function dismissed_conferences_do_not_show_up_in_conference_list()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create([
            'is_approved' => true,
        ]);
        $user->conferences()->save($conference);

        $this->actingAs($user)
            ->visit('conferences?filter=all')
            ->seePageIs('conferences?filter=all')
            ->see($conference->title);

        $this->actingAs($user)
            ->visit("conferences/{$conference->id}/dismiss");

        $this->actingAs($user)
            ->visit('conferences?filter=all')
            ->seePageIs('conferences?filter=all')
            ->dontSee($conference->title);
    }

    /** @test */
    function filtering_by_dismissed_shows_dismissed_conferences()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create([
            'is_approved' => true,
        ]);
        $user->conferences()->save($conference);

        $this->actingAs($user)
            ->visit("conferences/{$conference->id}/dismiss");

        $this->actingAs($user)
            ->visit('conferences?filter=dismissed')
            ->seePageIs('conferences?filter=dismissed')
            ->see($conference->title);
    }

    /** @test */
    function filtering_by_dismissed_does_not_show_undismissed_conferences()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create();
        $user->conferences()->save($conference);

        $this->actingAs($user)
            ->visit('conferences?filter=dismissed')
            ->seePageIs('conferences?filter=dismissed')
            ->dontSee($conference->title);
    }

    /** @test */
    function filtering_by_favorites_shows_favorite_conferences()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create([
            'is_approved' => true,
        ]);
        $user->conferences()->save($conference);

        $this->actingAs($user)
            ->visit("conferences/{$conference->id}/favorite");

        $this->actingAs($user)
            ->visit('conferences?filter=favorites')
            ->seePageIs('conferences?filter=favorites')
            ->see($conference->title);
    }

    /** @test */
    function filtering_by_favorites_does_not_show_nonfavorite_conferences()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create();
        $user->conferences()->save($conference);

        $this->actingAs($user)
            ->visit('conferences?filter=favorites')
            ->seePageIs('conferences?filter=favorites')
            ->dontSee($conference->title);
    }

    /** @test */
    function a_favorited_conference_cannot_be_dismissed()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create([
            'is_approved' => true,
        ]);
        $user->favoritedConferences()->save($conference);

        $this->actingAs($user)
            ->visit("conferences/{$conference->id}/dismiss");

        $this->actingAs($user)
            ->visit('conferences?filter=dismissed')
            ->seePageIs('conferences?filter=dismissed')
            ->dontSee($conference->title);
    }

    /** @test */
    function a_dismissed_conference_cannot_be_favorited()
    {
        $user = User::factory()->create();

        $conference = Conference::factory()->create([
            'is_approved' => true,
        ]);
        $user->dismissedConferences()->save($conference);

        $this->actingAs($user)
            ->visit("conferences/{$conference->id}/favorite");

        $this->actingAs($user)
            ->visit('conferences?filter=favorites')
            ->seePageIs('conferences?filter=favorites')
            ->dontSee($conference->title);
    }

    /** @test */
    function displaying_event_dates_with_no_dates_set()
    {
        $conference = Conference::factory()->make([
            'starts_at' => null,
            'ends_at' => null,
        ]);

        $this->assertNull($conference->event_dates_display);
    }

    /** @test */
    function displaying_event_dates_with_a_start_date_and_no_end_date()
    {
        $conference = Conference::factory()->make([
            'starts_at' => '2020-01-01 09:00:00',
            'ends_at' => null,
        ]);

        $this->assertEquals('Jan 1 2020', $conference->event_dates_display);
    }

    /** @test */
    function displaying_event_dates_with_an_end_date_and_no_start_date()
    {
        $conference = Conference::factory()->make([
            'starts_at' => null,
            'ends_at' => '2020-01-01 09:00:00',
        ]);

        $this->assertNull($conference->event_dates_display);
    }

    /** @test */
    function displaying_event_dates_with_the_same_start_and_end_dates()
    {
        $conference = Conference::factory()->make([
            'starts_at' => '2020-01-01 09:00:00',
            'ends_at' => '2020-01-01 16:00:00',
        ]);

        $this->assertEquals('Jan 1 2020', $conference->event_dates_display);
    }

    /** @test */
    function displaying_event_dates_with_the_different_start_and_end_dates()
    {
        $conference = Conference::factory()->make([
            'starts_at' => '2020-01-01 09:00:00',
            'ends_at' => '2020-01-03 16:00:00',
        ]);

        $this->assertEquals('Jan 1 2020 - Jan 3 2020', $conference->event_dates_display);
    }

    function assertConferenceSort($conferences)
    {
        foreach ($conferences as $sortPosition => $conference) {
            $sortedConference = $this->response->original->getData()['conferences']->values()[$sortPosition];

            $this->assertTrue($sortedConference->is($conference), "Conference ID {$conference->id} was expected in position {$sortPosition}, but {$sortedConference->id } was in position {$sortPosition}.");
        }
    }
}
