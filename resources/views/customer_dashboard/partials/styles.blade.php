<style>
:root {
    --mc-teal: #229992;
    --mc-teal-dark: #175d59;
    --mc-coral: #e5646b;
    --mc-yellow: #ffc346;
    --mc-cream: #f5f1ea;
    --mc-page: #f4f6fb;
    --mc-text: #173b4f;
    --mc-muted: #6d747b;
    --mc-white: #ffffff;
    --mc-border: rgba(31, 93, 90, .14);
    --mc-shadow: 0 16px 36px rgba(31, 93, 90, .10);
}

.customer-hidden {
    display: none !important;
}

.customer-dashboard {
    width: 100%;
    max-width: 1180px;
    margin: 42px auto;
    padding: 0 20px;
    box-sizing: border-box;
    color: var(--mc-text);
    font-family: Arial, Helvetica, sans-serif;
}

.customer-login-card,
.customer-loading-card,
.customer-status-card {
    max-width: 520px;
    margin: 0 auto;
    padding: 38px;
    box-sizing: border-box;
    background: var(--mc-white);
    border: 1px solid var(--mc-border);
    border-radius: 22px;
    box-shadow: var(--mc-shadow);
}

.customer-login-title {
    margin: 0 0 10px;
    color: var(--mc-text);
    font-size: 30px;
    font-weight: 800;
}

.customer-login-description {
    margin: 0 0 28px;
    color: var(--mc-muted);
    line-height: 1.65;
}

.customer-login-field {
    margin-bottom: 20px;
}

.customer-login-field label {
    display: block;
    margin-bottom: 8px;
    color: var(--mc-text);
    font-size: 14px;
    font-weight: 700;
}

.customer-login-field input {
    width: 100%;
    height: 52px;
    padding: 0 15px;
    box-sizing: border-box;
    border: 1px solid #d9dce5;
    border-radius: 12px;
    background: #fff;
    color: var(--mc-text);
    font-size: 16px;
    outline: none;
}

.customer-login-field input:focus {
    border-color: var(--mc-teal);
    box-shadow: 0 0 0 3px rgba(34, 153, 146, .14);
}

.customer-login-button,
.customer-return-login-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 50px;
    padding: 0 20px;
    border: 0;
    border-radius: 12px;
    background: var(--mc-teal);
    color: #fff;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
}

.customer-login-button {
    width: 100%;
}

.customer-login-button:disabled,
.mc-menu-item:disabled,
.mc-mobile-menu-button:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.customer-message {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 10px;
    font-size: 14px;
}

.customer-message-error {
    border: 1px solid #ffd1d1;
    background: #fff0f0;
    color: #c0392b;
}

.customer-loading-card,
.customer-status-card {
    text-align: center;
}

.customer-loading-spinner {
    width: 44px;
    height: 44px;
    margin: 0 auto 18px;
    border: 4px solid #e7e9f1;
    border-top-color: var(--mc-teal);
    border-radius: 50%;
    animation: mc-spin .8s linear infinite;
}

.customer-loading-text,
.customer-status-card p {
    margin: 0;
    color: var(--mc-muted);
    line-height: 1.65;
}

.customer-status-card h2 {
    margin: 0 0 12px;
}

.mc-status-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 60px;
    margin: 0 auto 18px;
    border-radius: 50%;
    background: rgba(229, 100, 107, .10);
    color: var(--mc-coral);
    font-size: 25px;
}

.customer-return-login-button {
    margin-top: 22px;
}

@keyframes mc-spin {
    to {
        transform: rotate(360deg);
    }
}

.mc-dashboard {
    width: 100%;
}

.mc-shell {
    display: grid;
    grid-template-columns: 270px minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}

.mc-sidebar {
    position: sticky;
    top: 24px;
    padding: 22px;
    box-sizing: border-box;
    background: var(--mc-white);
    border: 1px solid var(--mc-border);
    border-radius: 26px;
    box-shadow: var(--mc-shadow);
}

.mc-sidebar-header {
    position: relative;
    display: flex;
    align-items: center;
    gap: 13px;
    padding-bottom: 20px;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--mc-border);
}

.mc-customer-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    flex: 0 0 48px;
    border-radius: 50%;
    background: var(--mc-teal);
    color: #fff;
}

.mc-customer-information,
.mc-mobile-customer {
    min-width: 0;
}

.mc-customer-information strong,
.mc-mobile-customer strong {
    display: block;
    overflow: hidden;
    color: var(--mc-text);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mc-customer-information span,
.mc-mobile-customer span {
    display: block;
    margin-top: 4px;
    color: var(--mc-muted);
    font-size: 13px;
}

.mc-sidebar-menu {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.mc-menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 48px;
    padding: 0 14px;
    border: 0;
    border-radius: 14px;
    background: transparent;
    color: var(--mc-teal-dark);
    font-family: inherit;
    font-size: 15px;
    font-weight: 750;
    text-align: start;
    cursor: pointer;
    transition:
        background .2s ease,
        color .2s ease,
        transform .2s ease;
}

.mc-menu-item i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.mc-menu-item:hover,
.mc-menu-item.is-active {
    background: var(--mc-teal);
    color: #fff;
}

.mc-menu-item:hover {
    transform: translateX(2px);
}

.mc-logout-item {
    margin-top: 8px;
    color: var(--mc-coral);
}

.mc-logout-item:hover,
.mc-logout-item.is-active {
    background: var(--mc-coral);
    color: #fff;
}

.mc-main {
    min-width: 0;
}

.mc-content-card {
    min-height: 520px;
    padding: 40px;
    box-sizing: border-box;
    background: var(--mc-white);
    border: 1px solid var(--mc-border);
    border-radius: 28px;
    box-shadow: var(--mc-shadow);
}

.mc-dashboard-page {
    width: 100%;
}

.mc-dashboard-page > h1,
.mc-page-title {
    margin: 0;
    color: var(--mc-teal-dark);
    font-size: 32px;
    font-weight: 850;
}

.mc-dashboard-page > h1::after,
.mc-page-title::after {
    content: "";
    display: block;
    width: 54px;
    height: 5px;
    margin-top: 12px;
    border-radius: 999px;
    background: var(--mc-coral);
}

.mc-page-body {
    margin-top: 30px;
}

.mc-empty-state {
    padding: 26px;
    border: 1px dashed var(--mc-border);
    border-radius: 16px;
    color: var(--mc-muted);
    text-align: center;
}

.mc-points-card {
    display: flex;
    align-items: center;
    gap: 14px;
    max-width: 420px;
    margin-top: 30px;
    padding: 28px;
    border-radius: 20px;
    background: linear-gradient(135deg, #f8c146, #ffde7d);
    color: #fff;
}

.mc-points-card strong {
    font-size: 42px;
    line-height: 1;
}

.mc-points-icon {
    width: 48px;
    height: 48px;
}

.mc-mobile-header {
    display: none;
}

.mc-mobile-close-button {
    display: none;
}

.mc-mobile-overlay {
    display: none;
}

.mc-rtl .mc-menu-item {
    text-align: right;
}

@media (max-width: 899px) {
    .customer-dashboard {
        margin: 22px auto;
        padding: 0 14px;
    }

    .mc-shell {
        display: block;
    }

    .mc-mobile-header {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 14px;
        padding: 14px 16px;
        background: var(--mc-white);
        border: 1px solid var(--mc-border);
        border-radius: 18px;
        box-shadow: 0 10px 24px rgba(31, 93, 90, .08);
    }

    .mc-mobile-menu-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        padding: 0;
        border: 0;
        border-radius: 12px;
        background: var(--mc-teal);
        color: #fff;
        font-size: 17px;
        cursor: pointer;
    }

    .mc-mobile-customer {
        min-width: 0;
    }

    .mc-sidebar {
        position: fixed;
        z-index: 1002;
        top: 0;
        bottom: 0;
        left: 0;
        width: min(330px, 88vw);
        padding: 20px;
        border-radius: 0 24px 24px 0;
        overflow-y: auto;
        transform: translateX(-105%);
        transition: transform .25s ease;
    }

    .mc-rtl .mc-sidebar {
        right: 0;
        left: auto;
        border-radius: 24px 0 0 24px;
        transform: translateX(105%);
    }

    .mc-sidebar.is-open {
        transform: translateX(0);
    }

    .mc-mobile-close-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        margin-inline-start: auto;
        padding: 0;
        border: 0;
        border-radius: 10px;
        background: #eef3f3;
        color: var(--mc-text);
        font-size: 15px;
        cursor: pointer;
    }

    .mc-mobile-overlay {
        display: block;
        position: fixed;
        z-index: 1001;
        inset: 0;
        background: rgba(0, 0, 0, .42);
    }

    body.mc-menu-open {
        overflow: hidden;
    }

    .mc-content-card {
        min-height: 420px;
        padding: 26px 20px;
        border-radius: 22px;
    }

    .mc-dashboard-page > h1,
    .mc-page-title {
        font-size: 26px;
    }

    .mc-menu-item {
        min-height: 52px;
        font-size: 15px;
    }
}

@media (max-width: 600px) {
    .customer-login-card,
    .customer-loading-card,
    .customer-status-card {
        padding: 26px 20px;
    }

    .customer-login-title {
        font-size: 25px;
    }

    .mc-content-card {
        padding: 24px 16px;
    }

    .mc-points-card {
        align-items: flex-start;
        flex-direction: column;
    }
}

.mc-mobile-overlay[hidden] {
    display: none !important;
}

.mc-dashboard .fa-solid,
.mc-dashboard .fas {
    font-family: "Font Awesome 6 Free" !important;
    font-weight: 900 !important;
}

.mc-dashboard .fa-regular,
.mc-dashboard .far {
    font-family: "Font Awesome 6 Free" !important;
    font-weight: 400 !important;
}

.mc-dashboard .fa-brands,
.mc-dashboard .fab {
    font-family: "Font Awesome 6 Brands" !important;
    font-weight: 400 !important;
}

.mc-pets-page {
    width: 100%;
}

.mc-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 30px;
}

.mc-page-description {
    margin: 16px 0 0;
    color: var(--mc-muted);
    font-size: 15px;
    line-height: 1.7;
}

.mc-pets-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-top: 28px;
}

.mc-pet-card {
    position: relative;
    padding: 22px;
    border: 1px solid var(--mc-border);
    border-radius: 20px;
    background: #ffffff;
    box-shadow: 0 8px 24px rgba(31, 93, 90, .08);
    transition:
        transform .2s ease,
        box-shadow .2s ease,
        border-color .2s ease;
}

.mc-pet-card:hover {
    transform: translateY(-2px);
    border-color: rgba(34, 153, 146, .28);
    box-shadow: 0 12px 30px rgba(31, 93, 90, .12);
}

.mc-pet-card-header {
    display: flex;
    align-items: center;
    gap: 15px;
    padding-bottom: 18px;
    margin-bottom: 18px;
    border-bottom: 1px solid var(--mc-border);
}

.mc-pet-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 54px;
    flex: 0 0 54px;
    border-radius: 16px;
    background: rgba(34, 153, 146, .11);
    color: var(--mc-teal);
    font-size: 24px;
}

.mc-pet-heading {
    min-width: 0;
}

.mc-pet-name {
    margin: 0;
    color: var(--mc-text);
    font-size: 22px;
    font-weight: 800;
    line-height: 1.25;
}

.mc-pet-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
    color: var(--mc-muted);
    font-size: 14px;
}

.mc-pet-meta-separator {
    opacity: .55;
}

.mc-pet-details {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.mc-pet-detail {
    padding: 14px 15px;
    border-radius: 14px;
    background: #f7f9fa;
}

.mc-pet-detail-full {
    grid-column: 1 / -1;
}

.mc-pet-detail-label {
    display: block;
    margin-bottom: 6px;
    color: var(--mc-muted);
    font-size: 12px;
    font-weight: 700;
}

.mc-pet-detail strong {
    display: block;
    color: var(--mc-text);
    font-size: 15px;
    font-weight: 800;
    line-height: 1.45;
}

.mc-pet-detail p {
    margin: 0;
    color: var(--mc-text);
    font-size: 14px;
    line-height: 1.65;
}

.mc-pets-page .mc-empty-state {
    margin-top: 28px;
}

.mc-rtl .mc-pet-card,
.mc-rtl .mc-pet-heading,
.mc-rtl .mc-pet-detail {
    text-align: right;
}

.mc-rtl .mc-pet-card-header {
    direction: rtl;
}

.mc-rtl .mc-pet-meta {
    direction: rtl;
}

@media (max-width: 899px) {
    .mc-page-header {
        margin-bottom: 22px;
    }

    .mc-pets-grid {
        grid-template-columns: 1fr;
        gap: 16px;
        margin-top: 22px;
    }

    .mc-pet-card {
        padding: 18px;
        border-radius: 18px;
    }

    .mc-pet-card-header {
        gap: 13px;
    }

    .mc-pet-icon {
        width: 50px;
        height: 50px;
        flex-basis: 50px;
        border-radius: 14px;
        font-size: 22px;
    }

    .mc-pet-name {
        font-size: 20px;
    }
}

@media (max-width: 520px) {
    .mc-pet-details {
        grid-template-columns: 1fr;
    }

    .mc-pet-detail-full {
        grid-column: auto;
    }

    .mc-page-description {
        font-size: 14px;
    }
}

/* =========================================================
   ORDERS PAGE
   ========================================================= */

.mc-orders-page {
    width: 100%;
}

.mc-orders-page .mc-page-header {
    margin-bottom: 30px;
}

.mc-orders-sections {
    display: flex;
    flex-direction: column;
    gap: 32px;
}


/* =========================================================
   ORDER SECTION
   ========================================================= */

.mc-orders-section {
    width: 100%;
}

.mc-orders-section + .mc-orders-section {
    padding-top: 30px;
    border-top: 1px solid var(--mc-border);
}

.mc-orders-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 20px;
}

.mc-orders-section-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.mc-orders-section-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 48px;
    height: 48px;
    flex: 0 0 48px;

    border-radius: 14px;

    background: rgba(34, 153, 146, .11);
    color: var(--mc-teal);

    font-size: 19px;
}

.mc-orders-section-icon-history {
    background: rgba(229, 100, 107, .10);
    color: var(--mc-coral);
}

.mc-orders-section-title {
    margin: 0;

    color: var(--mc-text);

    font-size: 19px;
    font-weight: 800;
    line-height: 1.3;
}

.mc-orders-section-subtitle {
    margin: 5px 0 0;

    color: var(--mc-muted);

    font-size: 13px;
    line-height: 1.5;
}

.mc-orders-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 34px;
    height: 34px;
    padding: 0 10px;

    box-sizing: border-box;

    border-radius: 999px;

    background: var(--mc-teal);
    color: #ffffff;

    font-size: 13px;
    font-weight: 800;
}


/* =========================================================
   ORDERS LIST
   ========================================================= */

.mc-orders-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}


/* =========================================================
   ORDER CARD
   ========================================================= */

.mc-order-card {
    position: relative;

    width: 100%;
    padding: 20px;

    box-sizing: border-box;

    border: 1px solid var(--mc-border);
    border-radius: 18px;

    background: #ffffff;

    box-shadow:
        0 6px 20px rgba(31, 93, 90, .06);

    transition:
        border-color .2s ease,
        box-shadow .2s ease,
        transform .2s ease;
}

.mc-order-card:hover {
    transform: translateY(-1px);

    border-color:
        rgba(34, 153, 146, .28);

    box-shadow:
        0 10px 26px rgba(31, 93, 90, .10);
}


/* =========================================================
   CARD HEADER
   ========================================================= */

.mc-order-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;

    gap: 20px;

    padding-bottom: 16px;
    margin-bottom: 16px;

    border-bottom:
        1px solid var(--mc-border);
}

.mc-order-main-info {
    display: flex;
    align-items: center;
    gap: 13px;

    min-width: 0;
}

.mc-order-animal-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 46px;
    height: 46px;
    flex: 0 0 46px;

    border-radius: 13px;

    background:
        rgba(34, 153, 146, .10);

    color: var(--mc-teal);

    font-size: 19px;
}

.mc-order-heading {
    min-width: 0;
}

.mc-order-title {
    margin: 0;

    color: var(--mc-text);

    font-size: 17px;
    font-weight: 800;
    line-height: 1.35;
}

.mc-order-animal {
    display: flex;
    align-items: center;
    gap: 6px;

    margin-top: 5px;

    color: var(--mc-muted);

    font-size: 13px;
}


/* =========================================================
   STATUS BADGES
   ========================================================= */

.mc-order-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-height: 30px;
    padding: 5px 11px;

    box-sizing: border-box;

    border-radius: 999px;

    font-size: 12px;
    font-weight: 800;

    white-space: nowrap;
}

.mc-order-status-planned {
    background:
        rgba(34, 153, 146, .11);

    color: var(--mc-teal-dark);
}

.mc-order-status-prepared {
    background:
        rgba(255, 195, 70, .18);

    color: #9b6a00;
}

.mc-order-status-shipped {
    background:
        rgba(73, 125, 205, .12);

    color: #3569ad;
}

.mc-order-status-delivered {
    background:
        rgba(57, 160, 104, .12);

    color: #24824e;
}

.mc-order-status-overdue {
    background:
        rgba(229, 100, 107, .12);

    color: #c74850;
}


/* =========================================================
   ORDER INFORMATION
   ========================================================= */

.mc-order-info-grid {
    display: grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 12px;
}

.mc-order-info-item {
    padding: 13px 14px;

    border-radius: 13px;

    background: #f7f9fa;
}

.mc-order-info-label {
    display: block;

    margin-bottom: 5px;

    color: var(--mc-muted);

    font-size: 11px;
    font-weight: 700;
}

.mc-order-info-value {
    display: block;

    color: var(--mc-text);

    font-size: 14px;
    font-weight: 800;

    line-height: 1.4;
}


/* =========================================================
   ORDER ITEMS / RECIPES
   ========================================================= */

.mc-order-items {
    margin-top: 16px;
}

.mc-order-items-title {
    display: flex;
    align-items: center;
    gap: 7px;

    margin: 0 0 10px;

    color: var(--mc-text);

    font-size: 13px;
    font-weight: 800;
}

.mc-order-items-title i {
    color: var(--mc-teal);
}

.mc-order-items-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.mc-order-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    min-height: 34px;
    padding: 5px 11px;

    box-sizing: border-box;

    border: 1px solid var(--mc-border);
    border-radius: 10px;

    background: var(--mc-white);

    color: var(--mc-text);

    font-size: 12px;
    font-weight: 700;
}

.mc-order-item-quantity {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 23px;
    height: 23px;
    padding: 0 5px;

    box-sizing: border-box;

    border-radius: 7px;

    background:
        rgba(34, 153, 146, .10);

    color: var(--mc-teal-dark);

    font-size: 11px;
    font-weight: 800;
}


/* =========================================================
   DELIVERY TIMELINE
   ========================================================= */

.mc-order-timeline {
    display: flex;
    align-items: center;

    margin-top: 18px;
    padding-top: 17px;

    border-top:
        1px solid var(--mc-border);
}

.mc-order-timeline-step {
    position: relative;

    display: flex;
    align-items: center;
    flex: 1;

    min-width: 0;
}

.mc-order-timeline-step:last-child {
    flex: 0;
}

.mc-order-timeline-dot {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;
    justify-content: center;

    width: 28px;
    height: 28px;
    flex: 0 0 28px;

    border-radius: 50%;

    background: #e9eeee;
    color: #9ba7a7;

    font-size: 10px;
}

.mc-order-timeline-step::after {
    content: "";

    position: absolute;

    top: 13px;

    left: 28px;
    right: 0;

    height: 2px;

    background: #e9eeee;
}

.mc-order-timeline-step:last-child::after {
    display: none;
}

.mc-order-timeline-step.is-completed
.mc-order-timeline-dot {
    background: var(--mc-teal);
    color: #ffffff;
}

.mc-order-timeline-step.is-completed::after {
    background: var(--mc-teal);
}


/* =========================================================
   RTL
   ========================================================= */

.mc-rtl .mc-orders-page,
.mc-rtl .mc-orders-section,
.mc-rtl .mc-order-card {
    text-align: right;
}

.mc-rtl .mc-order-timeline-step::after {
    left: 0;
    right: 28px;
}


/* =========================================================
   TABLET / MOBILE
   ========================================================= */

@media (max-width: 899px) {

    .mc-orders-sections {
        gap: 28px;
    }

    .mc-orders-section + .mc-orders-section {
        padding-top: 26px;
    }

    .mc-orders-section-header {
        align-items: flex-start;
    }

    .mc-orders-section-icon {
        width: 44px;
        height: 44px;
        flex-basis: 44px;

        font-size: 17px;
    }

    .mc-order-card {
        padding: 17px;
        border-radius: 16px;
    }

    .mc-order-info-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}


/* =========================================================
   SMALL MOBILE
   ========================================================= */

@media (max-width: 560px) {

    .mc-orders-page .mc-page-header {
        margin-bottom: 24px;
    }

    .mc-orders-section-header {
        gap: 10px;
    }

    .mc-orders-section-title-wrap {
        align-items: flex-start;
        gap: 10px;
    }

    .mc-orders-section-title {
        font-size: 17px;
    }

    .mc-orders-section-subtitle {
        font-size: 12px;
    }

    .mc-orders-count {
        min-width: 30px;
        height: 30px;
        padding: 0 8px;
    }

    .mc-order-card-header {
        flex-direction: column;
        gap: 12px;
    }

    .mc-order-status {
        align-self: flex-start;
    }

    .mc-rtl .mc-order-status {
        align-self: flex-end;
    }

    .mc-order-info-grid {
        grid-template-columns: 1fr;
    }

    .mc-order-items-list {
        flex-direction: column;
        align-items: stretch;
    }

    .mc-order-item {
        width: 100%;
    }

    .mc-order-timeline {
        overflow-x: auto;
        padding-bottom: 5px;
    }

    .mc-order-timeline-step {
        min-width: 80px;
    }
}
</style>