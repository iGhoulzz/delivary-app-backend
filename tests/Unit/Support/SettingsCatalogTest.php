<?php

declare(strict_types=1);

use App\Support\SettingsCatalog;

it('exposes exactly the editable platform-setting keys', function (): void {
    expect(array_keys(SettingsCatalog::editable()))->toEqualCanonicalizing([
        'pricing.item_commission_rate',
        'pricing.driver_fee_cut_rate',
        'pricing.free_km',
        'pricing.per_km_rate',
        'payouts.clearance_hours',
        'payouts.min_amount',
        'payouts.allow_partial',
        'settlement.reverse_window_hours',
        'new_driver_max_liability',
    ]);
});

it('excludes the read-only item_size_modifiers key', function (): void {
    expect(SettingsCatalog::editable())->not->toHaveKey('pricing.item_size_modifiers');
    expect(SettingsCatalog::has('pricing.item_size_modifiers'))->toBeFalse();
});

it('declares a supported type and group for every editable key', function (): void {
    foreach (SettingsCatalog::editable() as $key => $meta) {
        expect($meta['type'])->toBeIn(['decimal', 'integer', 'boolean']);
        expect($meta['group'])->toBeIn(['pricing', 'payouts', 'settlement', 'risk']);
    }
});

it('treats free_km as decimal, matching the seeder', function (): void {
    expect(SettingsCatalog::meta('pricing.free_km')['type'])->toBe('decimal');
});

it('bounds rate keys to the 0..1 range', function (): void {
    foreach (['pricing.item_commission_rate', 'pricing.driver_fee_cut_rate'] as $key) {
        expect(SettingsCatalog::meta($key))->toMatchArray(['min' => 0, 'max' => 1]);
    }
});
