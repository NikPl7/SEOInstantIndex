(() => {
    const tooltips = document.querySelectorAll('[data-hcin-help]');
    let active = null;
    let pinned = false;

    const position = () => {
        if (!active) return;

        const trigger = active.trigger.getBoundingClientRect();
        const { width, height } = active.text.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = document.documentElement.clientHeight;

        if (trigger.bottom < 0 || trigger.top > viewportHeight || trigger.right < 0 || trigger.left > viewportWidth) {
            close();
            return;
        }

        const left = Math.max(8, Math.min(trigger.left, viewportWidth - width - 8));
        const above = trigger.top - height;
        const top = trigger.bottom + height > viewportHeight && above >= 0 ? above : trigger.bottom;
        active.text.style.left = `${left}px`;
        active.text.style.top = `${top}px`;
    };

    const close = () => {
        if (!active) return;
        active.trigger.setAttribute('aria-expanded', 'false');
        active.text.hidden = true;
        active = null;
        pinned = false;
    };

    const show = (tooltip) => {
        if (active === tooltip) return;
        close();
        active = tooltip;
        tooltip.text.hidden = false;
        tooltip.trigger.setAttribute('aria-expanded', 'true');
        position();
    };

    tooltips.forEach((root) => {
        const tooltip = {
            root,
            trigger: root.querySelector('button'),
            text: root.querySelector('[role="tooltip"]'),
        };

        root.addEventListener('pointerenter', (event) => {
            if (event.pointerType === 'mouse' && !pinned) show(tooltip);
        });
        root.addEventListener('pointerleave', (event) => {
            if (event.pointerType === 'mouse' && active === tooltip && !pinned
                && !tooltip.trigger.matches(':focus-visible')) close();
        });
        tooltip.trigger.addEventListener('focus', () => {
            if (tooltip.trigger.matches(':focus-visible')) show(tooltip);
        });
        tooltip.trigger.addEventListener('blur', () => {
            if (active === tooltip && (pinned || !root.matches(':hover'))) close();
        });
        tooltip.trigger.addEventListener('click', () => {
            if (active === tooltip && pinned) {
                close();
            } else {
                show(tooltip);
                pinned = active === tooltip;
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (active && !active.root.contains(event.target)) close();
    });
    document.addEventListener('keydown', (event) => {
        if (active && event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    });
    window.addEventListener('resize', position);
    document.addEventListener('scroll', position, { capture: true, passive: true });
})();
