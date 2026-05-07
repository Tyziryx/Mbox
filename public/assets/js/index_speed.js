document.addEventListener('DOMContentLoaded', () => {
    const circles = document.querySelectorAll('.speed-circle[data-percent]');

    circles.forEach((circle) => {
        const raw = circle.getAttribute('data-percent');
        if (raw == null || raw === '') return;

        const parsed = Number.parseFloat(raw);
        if (!Number.isFinite(parsed)) return;

        const clamped = Math.max(0, Math.min(100, parsed));
        circle.style.setProperty('--speed-circle-percent', `${clamped}%`);
    });
});
