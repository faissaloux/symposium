<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveConferenceRequest;
use App\Models\Conference;
use App\Services\Currency;
use App\Transformers\TalkForConferenceTransformer as TalkTransformer;
use Cknow\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class ConferencesController extends Controller
{
    public function index(Request $request)
    {
        switch ($request->input('filter')) {
            case 'favorites':
                $query = auth()->user()->favoritedConferences()->approved();
                break;
            case 'dismissed':
                $query = auth()->user()->dismissedConferences()->approved();
                break;
            case 'open_cfp':
                $query = Conference::undismissed()->openCfp()->approved();
                break;
            case 'unclosed_cfp':
                $query = Conference::undismissed()->unclosedCfp()->approved();
                break;
            case 'all':
                $query = Conference::undismissed()->approved();
                break;
            case 'future':
                // Pass through
            default:
                $query = Conference::undismissed()->future()->approved();
        }

        switch ($request->input('sort')) {
            case 'alpha':
                $query->orderBy('title');
                break;
            case 'date':
                $query->orderBy('starts_at');
                break;
            case 'opening_next':
                $query->orderByRaw('cfp_ends_at IS NULL, cfp_ends_at ASC');
                break;
            case 'closing_next':
                // pass through
            default:
                $query->orderByRaw('cfp_ends_at IS NULL, cfp_ends_at ASC');
                break;
        }

        return view('conferences.index', [
            'conferences' => $query->paginate(10)->withQueryString(),
        ]);
    }

    public function create()
    {
        return view('conferences.create', [
            'conference' => new Conference(),
            'currencies' => Currency::all(),
        ]);
    }

    public function store(SaveConferenceRequest $request)
    {
        $conference = Conference::create(array_merge($request->validated(), [
            'author_id' => auth()->user()->id,
            'speaker_package' => $request->speaker_package ? $this->formatSpeakerPackage($request->safe()->speaker_package) : null,
        ]));

        Event::dispatch('new-conference', [$conference]);
        Session::flash('success-message', 'Successfully created new conference.');

        return redirect('conferences/' . $conference->id);
    }

    public function show($id)
    {
        if (auth()->guest()) {
            return $this->showPublic($id);
        }

        try {
            if (auth()->user()->isAdmin()) {
                $conference = Conference::withoutGlobalScope('notRejected')->findOrFail($id);
            } else {
                $conference = Conference::findOrFail($id);
            }
        } catch (Exception $e) {
            return redirect('/');
        }

        $talks = auth()->user()->talks->sortByTitle()->map(function ($talk) use ($conference) {
            return TalkTransformer::transform($talk, $conference);
        });

        return view('conferences.show', [
            'conference' => $conference,
            'talks' => $talks,
        ]);
    }

    public function edit($id)
    {
        $conference = Conference::findOrFail($id);

        if ($conference->author_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            Log::error('User ' . auth()->user()->id . " tried to edit a conference they don't own.");

            return redirect('/');
        }

        return view('conferences.edit', [
            'conference' => $conference,
            'currencies' => Currency::all(),
            'package' => $conference->speaker_package->toDecimal(),
        ]);
    }

    public function update($id, SaveConferenceRequest $request)
    {
        // @todo Update this to use ACL... gosh this app is old...
        $conference = Conference::findOrFail($id);

        if ($conference->author_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            Log::error('User ' . auth()->user()->id . " tried to edit a conference they don't own.");

            return redirect('/');
        }

        // Save
        $conference->fill($request->validated());

        if ($request->speaker_package) {
            $conference->speaker_package = $this->formatSpeakerPackage($request->safe()->speaker_package);
        }

        if (auth()->user()->isAdmin()) {
            $conference->is_shared = $request->input('is_shared');
            $conference->is_approved = $request->input('is_approved');
        }

        $conference->save();

        Session::flash('success-message', 'Successfully edited conference.');

        return redirect('conferences/' . $conference->id);
    }

    public function destroy($id)
    {
        try {
            $conference = auth()->user()->conferences()->findOrFail($id);
        } catch (Exception $e) {
            Log::error('User ' . auth()->user()->id . " tried to delete a conference that doesn't exist or they don't own.");

            return redirect('/');
        }

        $conference->delete();

        Session::flash('success-message', 'Conference successfully deleted.');

        return redirect('conferences');
    }

    public function dismiss($conferenceId)
    {
        if (Conference::findOrFail($conferenceId)->isFavorited()) {
            return redirect()->back();
        }

        auth()->user()->dismissedConferences()->attach($conferenceId);

        return redirect()->back();
    }

    public function undismiss($conferenceId)
    {
        auth()->user()->dismissedConferences()->detach($conferenceId);

        return redirect()->back();
    }

    public function favorite($conferenceId)
    {
        if (Conference::findOrFail($conferenceId)->isDismissed()) {
            return redirect()->back();
        }

        auth()->user()->favoritedConferences()->attach($conferenceId);

        return redirect()->back();
    }

    public function unfavorite($conferenceId)
    {
        auth()->user()->favoritedConferences()->detach($conferenceId);

        return redirect()->back();
    }

    private function showPublic($id)
    {
        $conference = Conference::approved()->findOrFail($id);

        return view('conferences.showPublic', [
            'conference' => $conference,
        ]);
    }

    private function formatSpeakerPackage($package)
    {
        $speakerPackage = [
            'currency' => $package['currency'],
        ];

        // Since users have the ability to enter punctuation or not, then we want to use the appropriate parser
        foreach (['travel', 'food', 'hotel'] as $item) {
            $itemHasPunctuation = Str::of($package[$item])->contains([',', '.']);

            $speakerPackage[$item] = Money::parse($package[$item], $package['currency'], ! $itemHasPunctuation, App::currentLocale())->getAmount();
        }

        return $speakerPackage;
    }
}
