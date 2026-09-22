@php
    $data = $this->getData();
    $s = $data['stats'];
    $fmt = fn ($v) => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Verteilung LQ</x-slot>
        <x-slot name="description">
            @if($s)
                n = {{ $data['n'] }} · Median {{ $fmt($s['median']) }} · Q1–Q3 {{ $fmt($s['q1']) }}–{{ $fmt($s['q3']) }}
                · Min–Max {{ $fmt($s['min']) }}–{{ $fmt($s['max']) }}
                · <strong>{{ $s['below'] }}</strong> unter LQ {{ $data['threshold'] }}
            @else
                Noch keine gewerteten Versuche.
            @endif
        </x-slot>

        @if($s)
            <x-slot name="headerEnd">
                <div style="display:flex; flex-wrap:wrap; gap:.5rem; justify-content:flex-end;">
                    <x-filament::button size="sm" :color="$showBands ? 'primary' : 'gray'" :outlined="! $showBands"
                                        icon="heroicon-m-adjustments-horizontal" wire:click="$toggle('showBands')">
                        Förderbereiche
                    </x-filament::button>
                    <x-filament::button size="sm" :color="$byGender ? 'primary' : 'gray'" :outlined="! $byGender"
                                        icon="heroicon-m-user-group" wire:click="$toggle('byGender')">
                        Nach Geschlecht
                    </x-filament::button>
                </div>
            </x-slot>
        @endif

        <x-analysis.boxplot
            :groups="[[
                'key' => 'all', 'label' => 'Alle', 'n' => $data['n'], 'too_small' => false,
                'summary' => $s, 'gender' => null, 'points' => $data['values'],
            ]]"
            :labels="false"
            :domain="$data['domain']"
            :bands="$data['bands']"
            :threshold="$data['threshold']"
            :threshold-label="$data['threshold_label']"
            :show-bands="$s && $showBands"
            :by-gender="$s && $byGender"
            :gender-info="$data['groups']"
        />
    </x-filament::section>
</x-filament-widgets::widget>
