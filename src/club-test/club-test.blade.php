<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ФК выгрузка пользователей</title>
    @vite(['resources/js/club.js', 'resources/css/club.css'])
</head>
<body class="p-4 text-base" x-data="users_test">
<h1>Выгрузка пользователей</h1>
<a href="#" @click.prevent="downloadData()" class="underline text-sm">Выгрузить пользователей</a>
<template x-if="loading">
    <div class="text-xs text-green-700">Подождите, идет формирование отчета...</div>
</template>
</body>
</html>
