<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\DashboardController;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\LocalePreferenceService;

Route::get('lang/{locale}', function (
    string $locale,
    Request $request,
    LocalePreferenceService $locales,
    ActivityLogger $activityLogger
) {
    $languages = config('languages.available', []);

    abort_unless(array_key_exists($locale, $languages), 404);

    $oldLocale = $locales->resolve($request);
    $newLocale = $locales->persist($request, $locale);
    $user = $request->user();

    if ($user instanceof User && $oldLocale !== $newLocale) {
        $activityLogger->log($request, 'auth', 'language.changed', 'success', [
            'user' => $user,
            'properties_only' => true,
            'properties' => [
                'user_doc_num' => $user->doc_num,
                'username' => $user->username,
                'old_locale' => $oldLocale,
                'new_locale' => $newLocale,
            ],
        ]);
    }

    if ($request->expectsJson() || $request->ajax()) {
        return response()->json([
            'success' => true,
            'locale' => $newLocale,
            'dir' => $languages[$newLocale]['dir'] ?? 'ltr',
        ]);
    }

    return redirect()->back();
})->name('lang.switch');

Route::redirect('/', '/dashboard')->middleware(['auth']);

Route::get('/dashboard', DashboardController::class)->middleware(['auth'])->name('dashboard');
Route::get('/dashboard/data', [DashboardController::class, 'data'])->middleware(['auth'])->name('dashboard.data');
Route::get('/dashboard/summaries/sales', [DashboardController::class, 'salesSummary'])->middleware(['auth', 'can:dashboard.summaries.sales.view'])->name('dashboard.summaries.sales');
Route::get('/dashboard/summaries/purchases', [DashboardController::class, 'purchasesSummary'])->middleware(['auth', 'can:dashboard.summaries.purchases.view'])->name('dashboard.summaries.purchases');
Route::get('/dashboard/pending-decisions', [DashboardController::class, 'pendingDecisions'])->middleware(['auth'])->name('dashboard.pending-decisions');
