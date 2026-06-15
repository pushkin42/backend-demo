<?php

Route::get('club-test', function () {
    return view('club-test');
});

Route::post('club-test', function (Request $request, ClubTestAction $action) {
    $filename = storage_path('app/club-test.csv');
    if (!$action->makeReport($filename)) {
        throw new Exception('Не удалось сформировать содержимое для выгрузки');
    }
    return response()->download($filename);
});
