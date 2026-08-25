/**
 * Mikrotik Watch - JavaScript Principal
 *
 * Funções utilitárias e inicialização da aplicação.
 */

// ─── Utilitários ─────────────────────────────────────────────────────────────

const MikrotikWatch = {
    /**
     * Formata bytes em unidades legíveis (KB, MB, GB, etc.)
     */
    formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';

        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];

        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    },

    /**
     * Formata taxa em bits/s para unidades legíveis
     */
    formatRate(bitsPerSecond, decimals = 2) {
        if (bitsPerSecond === 0) return '0 bps';

        const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
        const k = 1000;
        const i = Math.floor(Math.log(bitsPerSecond) / Math.log(k));
        return parseFloat((bitsPerSecond / Math.pow(k, i)).toFixed(decimals)) + ' ' + units[i];
    },

    /**
     * Formata data/hora no padrão brasileiro
     */
    formatDateTime(dateString) {
        if (!dateString) return '—';
        const date = new Date(dateString);
        return date.toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    },

    /**
     * Requisição AJAX genérica
     */
    async fetchJSON(url, options = {}) {
        try {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    ...options.headers,
                },
                ...options,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            return await response.json();
        } catch (error) {
            console.error('Erro na requisição:', error);
            throw error;
        }
    },

    /**
     * Exibe uma notificação toast
     */
    showNotification(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            padding: 0.75rem 1.25rem;
            background: ${type === 'error' ? '#dc2626' : type === 'success' ? '#16a34a' : '#2563eb'};
            color: white;
            border-radius: 8px;
            font-size: 0.875rem;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        `;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },
};

// ─── Inicialização ───────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    console.log('Mikrotik Watch initialized.');
});
