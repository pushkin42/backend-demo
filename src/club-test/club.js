import Alpine from 'alpinejs';
import axios from 'axios';


document.addEventListener('alpine:init', () => {
    Alpine.data('users_test', () => {
        return {
            api: null,
            loading: false,

            async downloadData() {
                this.loading = true;
                try {
                    const response = await this.api.post('/club-test', {
                        responseType: 'blob',
                    });

                    const url = window.URL.createObjectURL(new Blob([response.data]));
                    const link = document.createElement('a');
                    link.href = url;

                    const fileName = 'downloaded_file.csv';

                    link.setAttribute('download', fileName);
                    document.body.appendChild(link);

                    link.click();
                    document.body.removeChild(link);
                    window.URL.revokeObjectURL(url);

                } catch (error) {
                    console.error('Ошибка при скачивании файла:', error);
                    alert("что-то пошло не так, попробуйте позже");
                } finally {
                    this.loading = false;
                }
            },

            init() {
                console.log('Users test init');
                this.api = axios.create({baseURL: 'https://my.ipsam.ru/'})
            },
        }
    })
})
Alpine.start();
