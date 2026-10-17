<?php

use App\Models\Announcement;

it('strips markdown emphasis and a redundant announcement prefix from titles', function () {
    expect(Announcement::plainTitle('**Announcement:** Season update'))
        ->toBe('Season update')
        ->and(Announcement::plainTitle('_Hello_ world'))
        ->toBe('Hello world')
        ->and(Announcement::plainTitle('  Normal title  '))
        ->toBe('Normal title');
});

it('exposes a display_title attribute on the model', function () {
    $announcement = new Announcement(['title' => '**Announcement:** paul test']);

    expect($announcement->display_title)->toBe('paul test');
});
