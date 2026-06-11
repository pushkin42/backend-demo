const catalogPage = (items) => {
    return {
        _items: Object.values(items)[0] ?? [],
        ready: false,

        params: {
            _perPage: Alpine.$persist(25).as('catalog:perPage').using(sessionStorage),
            _sort: new Map(),
            _page: 1,
        },

        init() {
            this.$watch('perPage', () => {
                this.page = 1;
            });
        },

        get page() {
            return Number(this.params._page);
        },

        set page(page) {
            const value = Number(page);

            this.params._page = Number.isFinite(value) && value > 0
                ? value
                : 1;
        },

        get perPage() {
            return Number(this.params._perPage);
        },

        set perPage(perPage) {
            const value = Number(perPage);

            this.params._perPage = Number.isFinite(value) && value > 0
                ? value
                : 25;
        },

        get totalItems() {
            return this._items.length;
        },

        get pages() {
            return Math.max(1, Math.ceil(this.totalItems / this.perPage));
        },

        get paginationText() {
            if (this.totalItems === 0) {
                return 'Нет товаров';
            }

            const startItem = (this.page - 1) * this.perPage + 1;
            const endItem = Math.min(this.page * this.perPage, this.totalItems);

            return `Показано ${startItem}-${endItem} товаров из ${this.totalItems}`;
        },

        get filterableFields() {
            return {
                name: 'Наименование',
                price: 'Цена',
                available: 'Наличие',
                views: 'Просмотры',
            };
        },

        get perPageValues() {
            return [5, 10, 25, 50, 100, 200];
        },

        get sortFields() {
            const fields = [];

            this.params._sort.forEach((direction, name) => {
                if (!name || !direction) {
                    return;
                }

                fields.push({ name, direction });
            });

            return fields;
        },

        get items() {
            this.ready = false;

            try {
                const items = [...(this._items ?? [])];
                const fields = this.sortFields;

                const sortedItems = fields.length
                    ? items.sort(this.multiSort(fields))
                    : items;

                return this.paginate(sortedItems, this.page, this.perPage);
            } finally {
                this.ready = true;
            }
        },

        paginate(items, page, perPage) {
            const start = (page - 1) * perPage;
            const end = start + perPage;

            return items.slice(start, end);
        },

        multiSort(fields) {
            return (a, b) => {
                for (const field of fields) {
                    const direction = field.direction === 'desc' ? -1 : 1;

                    const valA = this.getSortableValue(a, field.name);
                    const valB = this.getSortableValue(b, field.name);

                    if (valA === valB) {
                        continue;
                    }

                    if (typeof valA === 'string' || typeof valB === 'string') {
                        return String(valA ?? '').localeCompare(String(valB ?? ''), 'ru') * direction;
                    }

                    return (Number(valA ?? 0) - Number(valB ?? 0)) * direction;
                }

                return 0;
            };
        },

        getSortableValue(item, field) {
            return item?.[field] ?? item?.data?.[field] ?? null;
        },

        resetSort() {
            this.params._sort.clear();
            this.page = 1;
        },

        setPerPage(perPage) {
            const value = Number(perPage);

            if (value > 50) {
                const confirmed = confirm(
                    'Показ большого количества карточек может повлиять на производительность. Продолжить?'
                );

                if (!confirmed) {
                    return;
                }
            }

            this.perPage = value;
        },

        hasSort(field) {
            return this.params._sort.has(field);
        },

        getSort(field) {
            return this.params._sort.get(field) ?? null;
        },

        getSortString(field) {
            const sort = this.getSort(field);

            if (sort === 'asc') {
                return '↑';
            }

            if (sort === 'desc') {
                return '↓';
            }

            return '';
        },

        getSortArrow(field) {
            const sort = this.getSort(field);

            if (sort === 'asc') {
                return 'move-up';
            }

            if (sort === 'desc') {
                return 'move-down';
            }

            return 'trash';
        },

        toggleSort(field) {
            const current = this.params._sort.get(field);

            if (!current) {
                this.params._sort.set(field, 'asc');
            } else if (current === 'asc') {
                this.params._sort.set(field, 'desc');
            } else {
                this.params._sort.delete(field);
            }

            this.page = 1;
        },
    };
};

export { catalogPage };
