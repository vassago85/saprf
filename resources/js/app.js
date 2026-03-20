import './bootstrap';

if (document.body?.classList.contains('force-light')) {
    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = 'light';
    if (window.Flux) { window.Flux.applyAppearance('light'); }
}
