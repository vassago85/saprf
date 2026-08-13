<?php

namespace App\View\Components;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

class EventRegistrationCta extends Component
{
    public string $variant;
    public string $label;
    public string $url;
    public bool $disabled;
    public ?MatchRegistration $userRegistration;

    public function __construct(
        public MatchEvent $match,
        public string $size = 'md',
    ) {
        $user = Auth::user();
        $this->userRegistration = $match->userRegistration($user);
        $regStatus = $match->registration_status;

        if ($this->userRegistration) {
            $this->variant = 'registered';
            $this->label = 'View My Entry';
            $this->url = route('registrations.show', $this->userRegistration);
            $this->disabled = false;
        } elseif ($regStatus === 'open') {
            $this->variant = 'primary';
            $this->label = Auth::check() ? 'Register Now' : 'Login to Register';
            $this->url = Auth::check()
                ? url('/events/' . $match->id . '/register')
                : route('login', ['redirect' => url('/events/' . $match->id . '/register')]);
            $this->disabled = false;
        } elseif ($regStatus === 'waitlist') {
            $this->variant = 'waitlist';
            $this->label = 'Join Waitlist';
            $this->url = Auth::check()
                ? url('/events/' . $match->id . '/register')
                : route('login');
            $this->disabled = false;
        } elseif ($regStatus === 'upcoming') {
            $this->variant = 'muted';
            $this->label = 'Opens ' . ($match->registration_open_date?->format('j M') ?? 'Soon');
            $this->url = '#';
            $this->disabled = true;
        } elseif ($regStatus === 'full') {
            $this->variant = 'muted';
            $this->label = 'Full';
            $this->url = '#';
            $this->disabled = true;
        } elseif ($regStatus === 'cancelled') {
            $this->variant = 'muted';
            $this->label = 'Cancelled';
            $this->url = '#';
            $this->disabled = true;
        } elseif ($regStatus === 'setup_incomplete') {
            $this->variant = 'muted';
            $this->label = 'Not Open Yet';
            $this->url = '#';
            $this->disabled = true;
        } else {
            $this->variant = 'muted';
            $this->label = 'Registration Closed';
            $this->url = '#';
            $this->disabled = true;
        }
    }

    public function render(): View
    {
        return view('components.event-registration-cta');
    }
}
