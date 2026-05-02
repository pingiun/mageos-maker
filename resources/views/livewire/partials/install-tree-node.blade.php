@foreach ($nodes as $node)
    @if (! empty($node['children']))
        <details data-name="{{ $node['name'] }}" {{ $depth < 1 ? 'open' : '' }}>
            <summary>
                <span class="pkg-name {{ $node['sharedRefs'] > 1 ? 'shared' : '' }}">{{ $node['name'] }}</span><span
                    class="pkg-version">{{ $node['version'] }}</span><span
                    class="pkg-type">{{ $node['type'] }}</span>
            </summary>
            @include('livewire.partials.install-tree-node', ['nodes' => $node['children'], 'depth' => $depth + 1])
        </details>
    @else
        <div class="leaf" data-name="{{ $node['name'] }}"><span
                class="pkg-name {{ $node['sharedRefs'] > 1 ? 'shared' : '' }}">{{ $node['name'] }}</span><span
                class="pkg-version">{{ $node['version'] }}</span><span
                class="pkg-type">{{ $node['type'] }}</span></div>
    @endif
@endforeach
