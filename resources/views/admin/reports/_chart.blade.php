<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
@php($chartBackgroundColor = $backgroundColor ?? ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed'])
@php($chartBorderColor = $borderColor ?? '#2563eb')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById(@json($chartId));
        if (!el || typeof Chart === 'undefined') {
            return;
        }

        new Chart(el, {
            type: @json($type),
            data: {
                labels: @json($labels),
                datasets: [{
                    label: @json($label),
                    data: @json($values),
                    backgroundColor: @json($chartBackgroundColor),
                    borderColor: @json($chartBorderColor),
                    borderWidth: 2,
                    tension: 0.25,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true },
                },
            },
        });
    });
</script>
