@props([
    // auto  = black ink in light mode, white ink in dark mode (light surfaces)
    // light = always the white version (dark headers / gradients)
    // dark  = always the black version
    'tone' => 'auto',
])

@php($alt = 'حلول — دفتر ديون ذكي')

@if ($tone === 'light')
    <img src="{{ asset('brand/logo-white.png') }}" alt="{{ $alt }}" {{ $attributes->class('h-10 w-auto select-none') }} draggable="false">
@elseif ($tone === 'dark')
    <img src="{{ asset('brand/logo.png') }}" alt="{{ $alt }}" {{ $attributes->class('h-10 w-auto select-none') }} draggable="false">
@else
    <img src="{{ asset('brand/logo.png') }}" alt="{{ $alt }}" {{ $attributes->class('h-10 w-auto select-none dark:hidden') }} draggable="false">
    <img src="{{ asset('brand/logo-white.png') }}" alt="" aria-hidden="true" {{ $attributes->class('hidden h-10 w-auto select-none dark:block') }} draggable="false">
@endif
