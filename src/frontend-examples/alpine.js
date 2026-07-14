import Alpine from 'alpinejs';

import {htmlData, globalStore} from "./alpine/store/globalStore.js";
import {windowState} from "./alpine/store/windowState.js";
import './alpine/data/joditBlock.js';

// Импорт всех официальных плагинов Alpine.js
import collapse from '@alpinejs/collapse';   // Плавное скрытие/раскрытие блоков (x-collapse)
import focus from '@alpinejs/focus';         // Управление фокусом формы (x-trap)
import intersect from '@alpinejs/intersect'; // Отслеживание появления элемента на экране (x-intersect)
import mask from '@alpinejs/mask';           // Маски для инпутов (x-mask, например для дат/телефонов)
import morph from '@alpinejs/morph';         // Плавное обновление DOM дерева (используется Livewire под капотом)
import persist from '@alpinejs/persist';     // Сохранение состояния в LocalStorage (Alpine.$persist)
import anchor from '@alpinejs/anchor';       // Позиционирование всплывающих окон относительно кнопок (x-anchor)
import sort from '@alpinejs/sort';           // Сортировка списков перетаскиванием (drag-and-drop с x-sort)

import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import {userState} from "./alpine/store/userState.js";
import {catalogCardsStore} from "./alpine/store/catalogCardsStore.js";
import {heroData} from "./alpine/data/heroData.js";
import {scrollContainer} from "./alpine/data/scrollContainer.js";
import {horizontalSelector} from "./alpine/data/horizontalSelector.js";

import {catalogItemCardData} from "./alpine/data/catalog/catalogItemCard.js";
import {fp} from "./alpine/data/catalog/fullscreenPicture.js";

import './alpine/swiperDirective.js';
import {catalogPage} from "./alpine/data/catalog/catalogPage.js";
import {itemPage} from "./alpine/data/catalog/itemPage.js";
import {ratingContainer} from "./alpine/data/catalog/ratingContainer.js";
import {cartPage} from "./alpine/data/catalog/cartPage.js";
import {fb} from "./alpine/data/favButton.js";
import {toastsBlock} from "./alpine/data/toastsBlock.js";
import {ReactiveSet, ReactiveMap} from "./core/classes/ReactiveSet.js";
import {checkoutPageData} from "./alpine/data/catalog/checkoutPage.js";
import {deliveryBlock} from "./alpine/data/deliveryBlock.js";

window.catalogCardsStore = catalogCardsStore;
window.catalogItemCardData = catalogItemCardData;

Alpine.plugin(collapse);
Alpine.plugin(focus);
Alpine.plugin(intersect);
Alpine.plugin(mask);
Alpine.plugin(morph);
Alpine.plugin(persist);
Alpine.plugin(anchor);
Alpine.plugin(sort);


Alpine.directive('input-state', (el, {value, modifiers, expression}, {evaluateLater, effect, cleanup}) => {
    const getContent = evaluateLater(expression || "''");

    effect(() => {
        getContent((value) => {
            const span = document.createElement('span');
            value = (typeof value === 'string') ? value.trim() : '';
            span.innerText = value;
            span.classList.add('input-state');
            const tgt = el.closest('label').querySelector('span.input-state');
            if (!tgt) {
                el.closest('label').appendChild(span);
            } else {
                tgt.innerText = value;
            }
        })
    })
})

Alpine.directive('tippy', (el, {expression}, {evaluateLater, effect, cleanup}) => {
    const getContent = evaluateLater(expression || "''");

    const instance = tippy(el, {
        content: '',
        touch: 'hold',
    });

    effect(() => {
        getContent((value) => {
            instance.setContent(value == null ? '' : String(value));
        });
    });

    cleanup(() => instance.destroy());
});

// Глобальный доступ для отладки в консоли браузера
window.Alpine = Alpine;

window.addEventListener('alpine:init', () => {
    Alpine.store('globalStore', globalStore);
    Alpine.store('windowState', windowState);
    Alpine.store('userState', userState);
    Alpine.store('catalogCards', catalogCardsStore);

    Alpine.data('htmlData', htmlData);
    Alpine.data('heroData', heroData);
    Alpine.data('scrollContainer', scrollContainer);
    Alpine.data('horizontalSelector', horizontalSelector);

    Alpine.data('fullscreenPicture', fp);

    Alpine.data('itemPage', itemPage);
    Alpine.data('ratingContainer', ratingContainer);
    Alpine.data('cartPage', cartPage);
    Alpine.data('favButton', fb);
    Alpine.data('toastsBlock', toastsBlock);
    Alpine.data('checkoutPage', checkoutPageData);
    Alpine.data('deliveryBlock', deliveryBlock);
    Alpine.data('catalogPageFunc', catalogPage);

    window.globalStore = Alpine.store('globalStore');
    window.catalogCardsStore = Alpine.store('catalogCards');
    window.windowState = Alpine.store('windowState');
    window.userState = Alpine.store('userState');

    window.ReactiveMap = ReactiveMap;
})

// Запуск
Alpine.start();

window.enlargeItemPicture = (item)=> {
    func.event.dispatch('set-large-picture', {
        pictures: item.fullPictures, title: item.name, url: item.fullPictures[0], current: 0
    });
}
