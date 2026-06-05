<?php
/**
 * Renders Committee + Chapter Rep role cards.
 *
 * Inputs (PHP variables in scope before requiring):
 *   $variant : 'member' (Tailwind, used inside member portal)
 *              'public' (legacy .card / .grid CSS, used by PageBuilder shortcodes)
 *   $mode    : 'committee' | 'chapter-reps'
 *   $stateFilter : (optional, chapter-reps only) chapters.state value to restrict to
 *
 * Pulls data from CommitteeService so everything stays in one place. Cards
 * show the role's persistent contact info (aga.*/ar.* email + phone from the
 * roles catalog), NOT the member's personal contact — that's the whole
 * reason for the role-based contact model.
 */

use App\Services\CommitteeService;

$variant     = $variant     ?? 'member';
$mode        = $mode        ?? 'committee';
$stateFilter = $stateFilter ?? null;

$isMember = $variant === 'member';

// ── Card renderer (closure so the two modes can share it) ────────────────────
$renderCard = function (array $role) use ($isMember): string {
    $memberName = '';
    if (!empty($role['first_name']) || !empty($role['last_name'])) {
        $memberName = trim(($role['first_name'] ?? '') . ' ' . ($role['last_name'] ?? ''));
    }
    $vacant = $memberName === '';
    $avatar = $role['avatar_url'] ?? '';
    $email  = $role['email'] ?? '';
    $phone  = $role['phone'] ?? '';
    $roleTitle  = $role['name'] ?? '';
    $chapterName = $role['chapter_name'] ?? '';

    if ($isMember) {
        // ── Member-area variant (Tailwind cards) ─────────────────────────────
        $html  = '<div class="flex items-start gap-3 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">';
        if (!$vacant && $avatar !== '') {
            $html .= '<img src="' . e($avatar) . '" alt="' . e($memberName) . '" class="w-14 h-14 rounded-full object-cover border border-gray-200 shrink-0">';
        } else {
            $bgClass = $vacant ? 'bg-gray-50 border-gray-200' : 'bg-red-50 border-red-100';
            $iconClass = $vacant ? 'text-gray-300' : 'text-red-400';
            $html .= '<div class="w-14 h-14 rounded-full border flex items-center justify-center shrink-0 ' . $bgClass . '">'
                  .  '<span class="material-icons-outlined ' . $iconClass . ' text-2xl">person</span>'
                  .  '</div>';
        }
        $html .= '<div class="flex-1 min-w-0">';
        if ($vacant) {
            $html .= '<p class="font-semibold text-gray-400 italic truncate">Position vacant</p>';
        } else {
            $html .= '<p class="font-semibold text-gray-900 truncate">' . e($memberName) . '</p>';
        }
        $html .= '<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide bg-red-100 text-red-700 mt-0.5">' . e($roleTitle) . '</span>';
        if ($chapterName !== '') {
            $html .= '<p class="text-xs text-gray-500 mt-1 truncate">' . e($chapterName) . '</p>';
        }
        if (!$vacant && $phone !== '') {
            $html .= '<a href="tel:' . e(preg_replace('/\s+/', '', $phone)) . '" class="mt-1 text-xs text-primary hover:underline block">' . e($phone) . '</a>';
        }
        if (!$vacant && $email !== '') {
            $html .= '<a href="mailto:' . e($email) . '" class="text-xs text-primary hover:underline block truncate">' . e($email) . '</a>';
        }
        $html .= '</div></div>';
        return $html;
    }

    // ── Public variant (legacy .card CSS that matches existing pages) ────────
    $html  = '<div class="card">';
    if (!$vacant && $avatar !== '') {
        $html .= '<img src="' . e($avatar) . '" alt="' . e($memberName) . '" style="width:100%; border-radius:8px; margin-bottom:0.75rem;">';
    } else {
        $html .= '<img src="/uploads/about/committee-placeholder.png" alt="' . e($vacant ? 'Position vacant' : $memberName) . '" style="width:100%; border-radius:8px; margin-bottom:0.75rem;">';
    }
    if ($vacant) {
        $html .= '<h3 style="font-style:italic; color:#9ca3af;">Position vacant</h3>';
    } else {
        $html .= '<h3>' . e($memberName) . '</h3>';
    }
    $html .= '<p>' . e($roleTitle) . '</p>';
    if ($chapterName !== '') {
        $html .= '<p style="color:#6b7280; font-size:0.875rem;">' . e($chapterName) . '</p>';
    }
    if ($phone !== '') {
        $html .= '<p>Phone: <a href="tel:' . e(preg_replace('/\s+/', '', $phone)) . '">' . e($phone) . '</a></p>';
    } else {
        $html .= '<p>Phone: TBC</p>';
    }
    if ($email !== '') {
        $html .= '<p><a href="mailto:' . e($email) . '">' . e($email) . '</a></p>';
    }
    $html .= '</div>';
    return $html;
};

// ── National committee mode ──────────────────────────────────────────────────
if ($mode === 'committee') {
    $roles = CommitteeService::nationalRoles();

    if ($isMember) {
        echo '<section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">';
        echo '<div class="mb-6">';
        echo '<h2 class="font-display text-2xl font-bold text-gray-900">Committee &amp; Leadership</h2>';
        echo '<p class="text-sm text-gray-500 mt-1">Your national committee and chapter representatives.</p>';
        echo '</div>';
        echo '<div class="mb-8">';
        echo '<div class="flex items-center gap-2 mb-4">';
        echo '<div class="p-1.5 rounded-lg bg-red-100"><span class="material-icons-outlined text-red-600 text-base">star</span></div>';
        echo '<h3 class="font-display text-lg font-bold text-gray-900">National Committee</h3>';
        echo '</div>';
        echo '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">';
        foreach ($roles as $role) { echo $renderCard($role); }
        echo '</div></div>';

        // Show area reps below the national committee in the member area
        $byState = CommitteeService::chapterRolesByState();
        if ($byState) {
            echo '<div>';
            echo '<div class="flex items-center gap-2 mb-4">';
            echo '<div class="p-1.5 rounded-lg bg-indigo-100"><span class="material-icons-outlined text-indigo-600 text-base">place</span></div>';
            echo '<h3 class="font-display text-lg font-bold text-gray-900">Area Representatives</h3>';
            echo '</div>';
            foreach ($byState as $state => $stateRoles) {
                echo '<div class="mb-6">';
                echo '<p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-3">' . e((string) $state) . '</p>';
                echo '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">';
                foreach ($stateRoles as $role) { echo $renderCard($role); }
                echo '</div></div>';
            }
            echo '</div>';
        }
        echo '</section>';
    } else {
        echo '<div class="grid grid-3 committee-grid">';
        foreach ($roles as $role) { echo $renderCard($role); }
        echo '</div>';
    }
    return;
}

// ── Chapter reps mode (public pages use this; can filter by state) ───────────
if ($mode === 'chapter-reps') {
    $byState = CommitteeService::chapterRolesByState($stateFilter);
    if (!$byState) {
        if ($isMember) {
            echo '<p class="text-gray-500">No chapter representatives assigned yet.</p>';
        } else {
            echo '<div class="card"><p>No chapter representatives assigned yet. Contact the National President.</p></div>';
        }
        return;
    }
    foreach ($byState as $state => $stateRoles) {
        if ($stateFilter === null) {
            // showing all states, label each section
            if ($isMember) {
                echo '<div class="mb-6"><p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-3">' . e((string) $state) . '</p>';
                echo '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">';
                foreach ($stateRoles as $role) { echo $renderCard($role); }
                echo '</div></div>';
            } else {
                echo '<h2 style="margin-top:2rem;">' . e((string) $state) . '</h2>';
                echo '<div class="grid grid-3 chapter-grid">';
                foreach ($stateRoles as $role) { echo $renderCard($role); }
                echo '</div>';
            }
        } else {
            // single-state page — no header, just the grid
            if ($isMember) {
                echo '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">';
                foreach ($stateRoles as $role) { echo $renderCard($role); }
                echo '</div>';
            } else {
                echo '<div class="grid grid-3 chapter-grid">';
                foreach ($stateRoles as $role) { echo $renderCard($role); }
                echo '</div>';
            }
        }
    }
}
