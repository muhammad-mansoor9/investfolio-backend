<x-filament-widgets::widget>
    <div class="psx-welcome">
        <div class="psx-welcome-text">
            <p class="psx-welcome-date">{{ now('Asia/Karachi')->format('l, j F Y') }}</p>
            <h2>{{ $greeting }}{{ $name ? ', '.$name : '' }}</h2>
            <p>Here is how Investfolio is doing. The period filter changes the range of every chart.</p>
        </div>

        <div class="psx-welcome-actions">
            @foreach ($shortcuts as $shortcut)
                <x-filament::button
                    tag="a"
                    :href="$shortcut['url']"
                    :icon="$shortcut['icon']"
                    color="gray"
                    :badge="$shortcut['badge'] ?: null"
                    badge-color="danger"
                >
                    {{ $shortcut['label'] }}
                </x-filament::button>
            @endforeach

            @foreach ($links as $link)
                <x-filament::button tag="a" :href="$link['url']" :icon="$link['icon']" color="gray" target="_blank">
                    {{ $link['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
