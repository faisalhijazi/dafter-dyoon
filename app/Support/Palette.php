<?php

namespace App\Support;

/**
 * Tailwind class sets for the app's colour keys. Full class strings live here so
 * Tailwind's scanner (which also reads app/) can see them.
 */
class Palette
{
    /** Gradient avatar tiles (account initials, icon tiles). */
    public const TILE = [
        'amber' => 'bg-linear-to-br from-amber-400 to-amber-500 text-white shadow-amber-500/30',
        'cyan' => 'bg-linear-to-br from-cyan-400 to-cyan-500 text-white shadow-cyan-500/30',
        'pink' => 'bg-linear-to-br from-pink-400 to-pink-500 text-white shadow-pink-500/30',
        'violet' => 'bg-linear-to-br from-violet-400 to-violet-500 text-white shadow-violet-500/30',
        'emerald' => 'bg-linear-to-br from-emerald-400 to-emerald-500 text-white shadow-emerald-500/30',
        'rose' => 'bg-linear-to-br from-rose-400 to-rose-500 text-white shadow-rose-500/30',
        'sky' => 'bg-linear-to-br from-sky-400 to-sky-500 text-white shadow-sky-500/30',
        'orange' => 'bg-linear-to-br from-orange-400 to-orange-500 text-white shadow-orange-500/30',
        'indigo' => 'bg-linear-to-br from-indigo-400 to-indigo-500 text-white shadow-indigo-500/30',
        'slate' => 'bg-linear-to-br from-slate-500 to-slate-600 text-white shadow-slate-500/30',
        'red' => 'bg-linear-to-br from-red-400 to-red-500 text-white shadow-red-500/30',
        'blue' => 'bg-linear-to-br from-blue-400 to-blue-500 text-white shadow-blue-500/30',
        'teal' => 'bg-linear-to-br from-teal-400 to-teal-500 text-white shadow-teal-500/30',
        'dark' => 'bg-linear-to-br from-slate-700 to-slate-900 text-white shadow-slate-900/30',
    ];

    /** Soft badges (category chips). */
    public const BADGE = [
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'cyan' => 'bg-cyan-50 text-cyan-600 dark:bg-cyan-500/10 dark:text-cyan-400',
        'pink' => 'bg-pink-50 text-pink-600 dark:bg-pink-500/10 dark:text-pink-400',
        'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        'sky' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
        'orange' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400',
        'indigo' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    ];

    /** Solid dot for colour pickers. */
    public const DOT = [
        'amber' => 'bg-amber-500', 'cyan' => 'bg-cyan-500', 'pink' => 'bg-pink-500',
        'violet' => 'bg-violet-500', 'emerald' => 'bg-emerald-500', 'rose' => 'bg-rose-500',
        'sky' => 'bg-sky-500', 'orange' => 'bg-orange-500', 'indigo' => 'bg-indigo-500', 'slate' => 'bg-slate-500',
    ];

    private const AVATAR_ROTATION = ['amber', 'cyan', 'pink', 'blue', 'violet', 'teal', 'red', 'orange', 'emerald', 'indigo'];

    public static function tile(?string $key): string
    {
        return self::TILE[$key] ?? self::TILE['violet'];
    }

    public static function badge(?string $key): string
    {
        return self::BADGE[$key] ?? self::BADGE['slate'];
    }

    /** Stable per-account avatar colour. */
    public static function avatar(int $id): string
    {
        return self::tile(self::AVATAR_ROTATION[$id % count(self::AVATAR_ROTATION)]);
    }
}
