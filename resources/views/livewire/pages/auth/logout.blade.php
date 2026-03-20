<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function logout(): void
    {
        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/', navigate: true);
    }
}

?>

<div>
    <form wire:submit="logout">
        <flux:button type="submit" variant="ghost" size="sm">
            Sign out
        </flux:button>
    </form>
</div>
