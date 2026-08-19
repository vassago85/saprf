<?php

use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->member = User::factory()->create();
    $this->member->assignRole('member');
});

it('keeps required typeahead alpine data intact so results stays in scope', function () {
    $html = $this->actingAs($this->member)
        ->get(route('rifle-configurations.create'))
        ->assertOk()
        ->getContent();

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $input = $dom->getElementById('firearm_make_id_input');

    expect($input)->not->toBeNull();

    $root = $input->parentNode;
    while ($root instanceof DOMElement && ! $root->hasAttribute('x-data')) {
        $root = $root->parentNode;
    }

    expect($root)->toBeInstanceOf(DOMElement::class);

    $data = $root->getAttribute('x-data');

    expect($data)
        ->toContain('results:')
        ->toContain('syncValidity')
        ->toContain("setCustomValidity('')");
});
