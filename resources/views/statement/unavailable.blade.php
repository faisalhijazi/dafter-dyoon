@extends('statement.layout')

@section('title', 'الرابط غير متاح')

@section('content')
    <div class="mt-16 rounded-[32px] bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <div class="mx-auto flex size-16 items-center justify-center rounded-3xl bg-slate-100 text-3xl">🔒</div>
        <h1 class="mt-5 text-2xl font-black">رابط كشف الحساب غير متاح</h1>
        <p class="mt-3 text-slate-500">قد يكون الرابط غير صحيح، أو تم إيقافه أو تجديده من قِبل صاحب الحساب. تواصل معه للحصول على الرابط الحالي.</p>
    </div>
@endsection
