import Alpine from 'alpinejs';
import { globalStore } from '../../store/globalStore.js';

const PLACEHOLDER_URL = 'https://company-dom.ru/storage/images/catalog/placeholder.png';

Alpine.store('catalogCards', {
    cards: new Map(),
    _popular: [],
    _sort: null,
    mode: 'global',

    get popular() {
        return [...this._popular].sort((a, b) => {
            const priceA = Number(a.price ?? 0);
            const priceB = Number(b.price ?? 0);

            return this._sort === 'asc'
                ? priceA - priceB
                : priceB - priceA;
        });
    },

    set popular(items) {
        this._popular = Array.isArray(items) ? [...items] : [];
    },

    get(id) {
        return this.cards.get(id);
    },

    set(id, data) {
        this.cards.set(id, data);
    },

    async _fetchPopular(type = null) {
        if (this.mode !== 'popular') {
            return;
        }

        const url = new URL('test/popular-items-list', import.meta.env.VITE_API_BASE_URL);
        url.searchParams.set('type', type ?? 'all');

        const response = await fetch(url);

        if (response.ok) {
            const data = await response.json();
            this.popular = data.items ?? [];
        }
    },

    async init() {
        //
    },

    async setPopularType(type) {
        await this._fetchPopular(type);
    },

    setPopularSort(sort) {
        this._sort = sort;
    },
});

const catalogItemCardData = (data) => {
    return {
        id: data.article,
        data,

        caption: data.name,
        article: data.article,
        pictures: data.pictures ?? data.data?.pictures ?? [],
        currentPicture: 0,
        visible: false,

        _listeners: null,

        _internalState: {
            showButtons: false,
        },

        init() {
            this._listeners = {
                syncFavs: this.syncFavs.bind(this),
                syncViews: this.syncViews.bind(this),
            };

            window.addEventListener('sync-favs', this._listeners.syncFavs);
            window.addEventListener('sync-views', this._listeners.syncViews);

            this.cachePictures();
        },

        destroy() {
            if (!this._listeners) {
                return;
            }

            window.removeEventListener('sync-favs', this._listeners.syncFavs);
            window.removeEventListener('sync-views', this._listeners.syncViews);
        },

        get identifier() {
            return this.article ?? this.data.article ?? this.data.name;
        },

        get price() {
            return Number(this.data.price ?? this.data.data?.price ?? 0);
        },

        get oldPrice() {
            return Number(this.data.old_price ?? this.data.data?.old_price ?? 0);
        },

        get available() {
            return Number(this.data.available ?? this.data.data?.available ?? 0);
        },

        get stats() {
            return {
                views: Number(this.data.views ?? this.data.data?.views ?? 0),
                favs: Number(this.data.favs ?? this.data.data?.favs ?? 0),
                reviews: Number(this.data.reviews ?? this.data.data?.reviews ?? 0),
            };
        },

        get views() {
            return this.stats.views;
        },

        set views(value) {
            this.data.views = Number(value ?? 0);
        },

        get favs() {
            return this.stats.favs;
        },

        set favs(value) {
            this.data.favs = Number(value ?? 0);
        },

        get reviews() {
            return this.stats.reviews;
        },

        get inFav() {
            return userState.inFav(this.identifier);
        },

        get inCart() {
            return Number(userState.inCart(this.identifier));
        },

        set inCart(value) {
            let quantity = Number(value ?? 0);

            if (quantity > this.available) {
                quantity = this.available;
            }

            if (quantity < 0) {
                quantity = 0;
            }

            userState.setInCart(this.identifier, quantity, this.price);
        },

        get showButtons() {
            return this._internalState.showButtons || this.inFav;
        },

        set showButtons(value) {
            this._internalState.showButtons = Boolean(value);
        },

        get currentPictureUrl() {
            if (!this.pictures.length) {
                return PLACEHOLDER_URL;
            }

            return this.pictures[this.currentPicture] ?? PLACEHOLDER_URL;
        },

        get catalogItemLink() {
            return new URL(`/catalog/item/${this.identifier}`, import.meta.env.VITE_BASE_URL);
        },

        get picturesForCard() {
            return this.pictures.slice(0, 6);
        },

        cachePictures() {
            if (!this.visible) {
                return;
            }

            this.pictures.forEach((url) => {
                globalStore.cacheImage(url);
            });
        },

        syncFavs(event) {
            if (typeof event === 'number') {
                this.favs = event;
                return;
            }

            if (event.detail?.item === this.identifier) {
                this.favs = Number(event.detail.count ?? 0);
            }
        },

        syncViews(event) {
            if (typeof event === 'number') {
                this.views = event;
                return;
            }

            if (event.detail?.item === this.identifier) {
                this.views = Number(event.detail.count ?? 0);
            }
        },

        enlargePicture() {
            this.$el._tippy?.hide();

            this.$dispatch('set-large-picture', {
                pictures: this.pictures,
                title: this.caption,
                url: this.currentPictureUrl,
                current: this.currentPicture,
            });

            userState.incrementViewsCounter(this.identifier);
        },

        buttons: {
            add_to_cart: {
                ['x-text']() {
                    return this.inCart ? 'В корзине' : 'В корзину';
                },

                [':class']() {
                    return {
                        active: this.inCart,
                    };
                },

                [':disabled']() {
                    return !this.available || this.inCart >= this.available;
                },

                ['@click.prevent']() {
                    this.inCart++;
                },
            },
        },

        enlarge_button: {
            ['@click.stop.prevent']() {
                this.enlargePicture();
            },

            ['x-show']() {
                return this.picturesForCard.length;
            },
        },

        fav_button: {
            ['@click.stop.prevent']() {
                userState.toggleFav(data);
            },

            [':class']() {
                return {
                    active: userState.inFav(this.identifier),
                };
            },

            ['x-tippy']() {
                return userState.inFav(this.identifier)
                    ? 'В избранном'
                    : 'В избранное';
            },
        },
    };
};

const catalogCardsStore = Alpine.store('catalogCards');

export { catalogItemCardData, catalogCardsStore };
