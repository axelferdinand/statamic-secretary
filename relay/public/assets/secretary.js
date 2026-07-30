document.documentElement.classList.remove('no-js');
document.documentElement.classList.add('js');

const analyticsMeta = document.querySelector('meta[name="google-analytics-id"]');
const analyticsMeasurementId = /^G-[A-Z0-9]{4,20}$/.test(analyticsMeta?.content || '')
    ? analyticsMeta.content
    : null;
const analyticsConsentKey = 'statamic-secretary-analytics-consent-v1';
const consentManager = document.querySelector('[data-consent-manager]');

const readAnalyticsConsent = () => {
    try {
        return window.localStorage.getItem(analyticsConsentKey);
    } catch {
        return null;
    }
};

const storeAnalyticsConsent = (choice) => {
    try {
        window.localStorage.setItem(analyticsConsentKey, choice);
    } catch {
        // Consent still applies for this page even when storage is unavailable.
    }
};

const prepareGoogleConsent = () => {
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function gtag() {
        window.dataLayer.push(arguments);
    };

    window.gtag('consent', 'default', {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
        functionality_storage: 'denied',
        personalization_storage: 'denied',
        security_storage: 'granted',
        wait_for_update: 500,
    });

    window.gtag('set', 'ads_data_redaction', true);
    window.gtag('set', 'allow_google_signals', false);
    window.gtag('set', 'allow_ad_personalization_signals', false);
};

const loadAnalytics = () => {
    if (!analyticsMeasurementId) {
        return;
    }

    prepareGoogleConsent();
    window[`ga-disable-${analyticsMeasurementId}`] = false;
    window.gtag('consent', 'update', { analytics_storage: 'granted' });

    if (document.querySelector('[data-secretary-google-tag]')) {
        return;
    }

    window.gtag('js', new Date());
    window.gtag('config', analyticsMeasurementId, {
        allow_google_signals: false,
        allow_ad_personalization_signals: false,
        anonymize_ip: true,
    });

    const googleTag = document.createElement('script');
    googleTag.async = true;
    googleTag.dataset.secretaryGoogleTag = '';
    googleTag.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(analyticsMeasurementId)}`;
    document.head.append(googleTag);
};

const clearAnalyticsCookies = () => {
    const hostname = window.location.hostname;
    const domainCandidates = ['', hostname, `.${hostname}`, '.statamic.no'];

    document.cookie.split(';').forEach((cookie) => {
        const name = cookie.split('=')[0]?.trim();

        if (!name?.startsWith('_ga')) {
            return;
        }

        domainCandidates.forEach((domain) => {
            const domainAttribute = domain ? `; Domain=${domain}` : '';
            document.cookie = `${name}=; Max-Age=0; Path=/${domainAttribute}; SameSite=Lax`;
        });
    });
};

const showConsentManager = (moveFocus = false) => {
    if (!consentManager) {
        return;
    }

    consentManager.hidden = false;

    if (moveFocus) {
        consentManager.querySelector('[data-consent-accept]')?.focus();
    }
};

const hideConsentManager = () => {
    if (consentManager) {
        consentManager.hidden = true;
    }
};

if (analyticsMeasurementId && consentManager) {
    const choice = readAnalyticsConsent();

    if (choice === 'accepted') {
        loadAnalytics();
    } else if (choice === 'declined') {
        window[`ga-disable-${analyticsMeasurementId}`] = true;
    } else {
        showConsentManager();
    }

    document.querySelectorAll('[data-consent-accept]').forEach((button) => {
        button.addEventListener('click', () => {
            storeAnalyticsConsent('accepted');
            loadAnalytics();
            hideConsentManager();
        });
    });

    document.querySelectorAll('[data-consent-decline]').forEach((button) => {
        button.addEventListener('click', () => {
            storeAnalyticsConsent('declined');
            window[`ga-disable-${analyticsMeasurementId}`] = true;

            if (typeof window.gtag === 'function') {
                window.gtag('consent', 'update', { analytics_storage: 'denied' });
            }

            clearAnalyticsCookies();
            hideConsentManager();
        });
    });

    document.querySelectorAll('[data-consent-open]').forEach((button) => {
        button.addEventListener('click', () => showConsentManager(true));
    });
}

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const header = document.querySelector('[data-header]');

if (header) {
    const updateHeader = () => {
        header.classList.toggle('is-scrolled', window.scrollY > 72);
    };

    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });
}

const revealItems = document.querySelectorAll('[data-reveal]');

if ('IntersectionObserver' in window && !reducedMotion) {
    const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, {
        rootMargin: '0px 0px -8% 0px',
        threshold: 0.08,
    });

    revealItems.forEach((item) => revealObserver.observe(item));
} else {
    revealItems.forEach((item) => item.classList.add('is-visible'));
}

document.querySelectorAll('.faq-list details').forEach((detail) => {
    detail.addEventListener('toggle', () => {
        if (!detail.open) {
            return;
        }

        document.querySelectorAll('.faq-list details[open]').forEach((openDetail) => {
            if (openDetail !== detail) {
                openDetail.open = false;
            }
        });
    });
});

const demo = document.querySelector('[data-demo]');

if (demo) {
    const tabs = demo.querySelectorAll('[data-demo-channel]');
    const emailFields = demo.querySelector('[data-email-fields]');
    const prompt = demo.querySelector('[data-demo-prompt]');
    const promptData = demo.querySelector('.demo-prompt-data');
    const examples = demo.querySelectorAll('[data-demo-example]');
    const compose = demo.querySelector('[data-demo-compose]');
    const progress = demo.querySelector('[data-demo-progress]');
    const result = demo.querySelector('[data-demo-result]');
    const submit = demo.querySelector('[data-demo-submit]');
    const submitLabel = demo.querySelector('[data-demo-submit-label]');
    const status = demo.querySelector('[data-demo-status]');
    const steps = Array.from(demo.querySelectorAll('[data-demo-step]'));
    const reset = demo.querySelector('[data-demo-reset]');
    const originalSubmitLabel = submitLabel?.textContent || '';
    const timers = [];

    const clearTimers = () => {
        while (timers.length) {
            window.clearTimeout(timers.pop());
        }
    };

    const setChannel = (selectedTab) => {
        tabs.forEach((tab) => {
            const selected = tab === selectedTab;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        });

        if (emailFields) {
            emailFields.hidden = selectedTab.dataset.demoChannel !== 'email';
        }
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => setChannel(tab));
    });

    examples.forEach((example) => {
        example.addEventListener('click', () => {
            examples.forEach((candidate) => candidate.classList.toggle('is-active', candidate === example));

            const key = `prompt${example.dataset.demoExample}`;

            if (prompt && promptData?.dataset[key]) {
                prompt.value = promptData.dataset[key];
                prompt.focus();
                prompt.setSelectionRange(prompt.value.length, prompt.value.length);
            }
        });
    });

    const showResult = () => {
        if (progress) {
            progress.hidden = true;
        }

        if (result) {
            result.hidden = false;
        }

        reset?.focus();
    };

    const runDemo = () => {
        clearTimers();

        if (!compose || !progress || !result || !submit) {
            return;
        }

        submit.disabled = true;
        submitLabel.textContent = promptData?.dataset.running || originalSubmitLabel;
        compose.hidden = true;
        result.hidden = true;
        progress.hidden = false;

        steps.forEach((step) => {
            step.classList.remove('is-active', 'is-done');
        });

        const delay = reducedMotion ? 80 : 560;

        steps.forEach((step, index) => {
            timers.push(window.setTimeout(() => {
                steps.forEach((candidate, candidateIndex) => {
                    candidate.classList.toggle('is-active', candidateIndex === index);

                    if (candidateIndex < index) {
                        candidate.classList.add('is-done');
                    }
                });

                const stepText = step.querySelector('p')?.textContent;

                if (status && stepText) {
                    status.textContent = stepText;
                }
            }, delay * index));
        });

        timers.push(window.setTimeout(() => {
            steps.forEach((step) => {
                step.classList.remove('is-active');
                step.classList.add('is-done');
            });

            showResult();
        }, delay * steps.length + (reducedMotion ? 60 : 260)));
    };

    const resetDemo = () => {
        clearTimers();

        if (compose) {
            compose.hidden = false;
        }

        if (progress) {
            progress.hidden = true;
        }

        if (result) {
            result.hidden = true;
        }

        if (submit) {
            submit.disabled = false;
        }

        if (submitLabel) {
            submitLabel.textContent = originalSubmitLabel;
        }

        if (status) {
            status.textContent = promptData?.dataset.running || '';
        }

        steps.forEach((step) => {
            step.classList.remove('is-active', 'is-done');
        });

        prompt?.focus();
    };

    submit?.addEventListener('click', runDemo);
    reset?.addEventListener('click', resetDemo);
}
