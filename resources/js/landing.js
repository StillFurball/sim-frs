/**
 * Landing page behaviour: the live trial card, section entrances and the drifting showcase photograph.
 * Everything here is an enhancement; the page reads completely without it.
 */

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

document.documentElement.classList.add('js');

/**
 * Convert "HH:MM" to minutes since midnight.
 *
 * @param {string} time
 * @returns {number}
 */
const toMinutes = (time) => {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
};

/**
 * Whether two classes share a day and overlapping hours (mirrors CourseClass::overlaps()).
 */
const overlaps = (a, b) => a.day === b.day && toMinutes(a.start) < toMinutes(b.end) && toMinutes(b.start) < toMinutes(a.end);

function initTrialCard() {
    const dataElement = document.getElementById('trial-data');

    if (!dataElement) {
        return;
    }

    const data = JSON.parse(dataElement.textContent);
    const classes = new Map();

    data.courses.forEach((course) => {
        course.classes.forEach((courseClass) => classes.set(courseClass.id, { ...courseClass, course }));
    });

    const state = {
        selected: new Set(data.selected),
        max: data.max,
    };

    const week = document.querySelector('[data-week]');
    const sksOutput = document.querySelector('[data-sks]');
    const maxOutput = document.querySelector('[data-max]');
    const note = document.querySelector('[data-note]');
    const tally = note.closest('.tally');
    const classButtons = [...document.querySelectorAll('[data-class]')];
    const limitButtons = [...document.querySelectorAll('[data-limit]')];

    const selectedClasses = () => [...state.selected].map((id) => classes.get(id));

    const clashingIds = () => {
        const picked = selectedClasses();
        const ids = new Set();

        picked.forEach((a, index) => {
            picked.slice(index + 1).forEach((b) => {
                if (overlaps(a, b)) {
                    ids.add(a.id);
                    ids.add(b.id);
                }
            });
        });

        return ids;
    };

    const render = () => {
        const picked = selectedClasses();
        const clashes = clashingIds();
        const sks = picked.reduce((total, courseClass) => total + courseClass.course.sks, 0);

        [...week.rows].forEach((row, rowIndex) => {
            const start = data.startTimes[rowIndex];

            [...row.cells].slice(1).forEach((cell, dayIndex) => {
                const here = picked.filter((courseClass) => courseClass.day === dayIndex + 1 && courseClass.start === start);
                const signature = here.map((courseClass) => `${courseClass.id}:${clashes.has(courseClass.id)}`).join(',');

                if (cell.dataset.signature === signature) {
                    return;
                }

                cell.dataset.signature = signature;
                cell.replaceChildren(
                    ...here.map((courseClass) => {
                        const slot = document.createElement('span');
                        const name = document.createElement('b');
                        const room = document.createElement('span');

                        slot.className = clashes.has(courseClass.id) ? 'slot slot--clash' : 'slot';
                        name.textContent = `${courseClass.course.short} ${courseClass.label}`;
                        room.textContent = clashes.has(courseClass.id) ? 'Bentrok' : courseClass.room ?? '';
                        slot.append(name, room);

                        return slot;
                    }),
                );
            });
        });

        classButtons.forEach((button) => {
            const courseClass = classes.get(Number(button.dataset.class));
            const isPicked = state.selected.has(courseClass.id);
            const wouldClash = picked.some((other) => other.course.id !== courseClass.course.id && overlaps(courseClass, other));

            button.setAttribute('aria-pressed', String(isPicked));
            button.dataset.clash = String(isPicked ? clashes.has(courseClass.id) : wouldClash);
            button.title = wouldClash && !isPicked ? 'Bentrok dengan kelas yang sudah kamu ambil' : '';
        });

        limitButtons.forEach((button) => button.setAttribute('aria-checked', String(Number(button.dataset.limit) === state.max)));

        sksOutput.textContent = String(sks);
        maxOutput.textContent = String(state.max);

        const clashCount = clashes.size;

        if (sks > state.max) {
            tally.dataset.state = 'over';
            note.textContent = `Lewat ${sks - state.max} SKS dari batasmu`;
        } else if (clashCount > 0) {
            tally.dataset.state = 'clash';
            note.textContent = `${clashCount} kelas bentrok`;
        } else {
            tally.dataset.state = 'ok';
            note.textContent = sks === 0 ? 'Kartu masih kosong' : 'Tidak ada bentrok';
        }
    };

    classButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const courseClass = classes.get(Number(button.dataset.class));

            if (state.selected.has(courseClass.id)) {
                state.selected.delete(courseClass.id);
            } else {
                courseClass.course.classes.forEach((sibling) => state.selected.delete(sibling.id));
                state.selected.add(courseClass.id);
            }

            render();
        });
    });

    limitButtons.forEach((button) => {
        button.addEventListener('click', () => {
            state.max = Number(button.dataset.limit);
            render();
        });

        button.addEventListener('keydown', (event) => {
            const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];

            if (!step) {
                return;
            }

            event.preventDefault();
            const next = limitButtons[(limitButtons.indexOf(button) + step + limitButtons.length) % limitButtons.length];
            next.focus();
            next.click();
        });
    });

    render();
}

function initEntrances() {
    const targets = document.querySelectorAll('[data-reveal], .slips, .reply');

    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
        targets.forEach((target) => target.classList.add('is-in'));

        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    observer.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -12% 0px', threshold: 0.05 },
    );

    targets.forEach((target) => observer.observe(target));
}

function initDrift() {
    const photo = document.querySelector('[data-drift]');

    if (!photo || prefersReducedMotion || CSS.supports('animation-timeline: view()')) {
        return;
    }

    let frame = 0;

    const update = () => {
        frame = 0;
        const rect = photo.parentElement.getBoundingClientRect();
        const progress = Math.min(Math.max(1 - (rect.bottom / (window.innerHeight + rect.height)), 0), 1);
        photo.style.transform = `translate3d(0, ${(progress - 0.5) * -8}%, 0) scale(${1.14 - progress * 0.06})`;
    };

    window.addEventListener('scroll', () => {
        if (!frame) {
            frame = requestAnimationFrame(update);
        }
    }, { passive: true });

    update();
}

function initPasswordToggles() {
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        const input = document.getElementById(button.dataset.togglePassword);

        button.addEventListener('click', () => {
            const isShown = input.type === 'text';

            input.type = isShown ? 'password' : 'text';
            button.setAttribute('aria-pressed', String(!isShown));
            button.textContent = isShown ? button.dataset.labelShow : button.dataset.labelHide;
        });
    });
}

initTrialCard();
initPasswordToggles();
initEntrances();
initDrift();
