@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">
            <i class="fas fa-gift me-2 text-mauve"></i>Loyalty Program
        </h3>
    </div>

    @if($subscriptions->isEmpty())
        <div class="card">
            <div class="card-body text-muted">
                No customers found.
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-header bg-mauve text-white">
                Customers
            </div>

            <div class="card-body table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Province</th>
                            <th>Zone</th>
                            <th>Valid Points</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($subscriptions as $subscription)
                            <tr>
                                <td>{{ $subscription->code }}</td>
                                <td>{{ trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name) }}</td>
                                <td>{{ $subscription->subscriber_phone }}</td>
                                <td>{{ $subscription->subscriber_province }}</td>
                                <td>{{ $subscription->subscriber_zone }}</td>
                                <td>
                                    <span class="badge bg-success loyalty-points-badge">
                                        {{ $subscription->valid_loyalty_points }} points
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button type="button"
                                            class="btn btn-sm btn-mauve open-loyalty-modal"
                                            data-subscription-id="{{ $subscription->id }}"
                                            data-history-url="{{ route('loyalty.history', $subscription) }}"
                                            data-store-url="{{ route('loyalty.points.store', $subscription) }}">
                                        View History
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<div class="modal fade" id="loyaltyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="loyaltyCustomerName">Customer Loyalty</h5>
                    <div class="small text-muted">
                        Valid points: <span id="loyaltyValidPoints">0</span>
                    </div>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Points History</h6>

                    <button type="button" class="btn btn-sm btn-mauve" id="showAddPointsForm">
                        Offer Points
                    </button>
                </div>

                <div id="addPointsFormWrap" class="card mb-3" style="display:none;">
                    <div class="card-body">
                        <form id="addPointsForm">
                            @csrf

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Points *</label>
                                    <input type="number"
                                           class="form-control"
                                           id="pointsInput"
                                           name="points"
                                           min="1"
                                           required>
                                </div>

                                <div class="col-md-5 mb-3">
                                    <label class="form-label">Label *</label>
                                    <input type="text"
                                           class="form-control"
                                           id="labelInput"
                                           name="label"
                                           required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Expiration Date</label>
                                    <input type="date"
                                           class="form-control"
                                           id="expiresAtInput"
                                           name="expires_at">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-mauve">
                                Save Points
                            </button>
                        </form>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Label</th>
                                <th>Points</th>
                                <th>Expiration</th>
                                <th>Status</th>
                                <th>Created By</th>
                            </tr>
                        </thead>
                        <tbody id="loyaltyHistoryBody">
                            <tr>
                                <td colspan="6" class="text-muted text-center">
                                    No history loaded.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let currentSubscriptionId = null;
    let currentHistoryUrl = null;
    let currentStoreUrl = null;

    const modalEl = document.getElementById('loyaltyModal');
    const modal = new bootstrap.Modal(modalEl);

    const customerNameEl = document.getElementById('loyaltyCustomerName');
    const validPointsEl = document.getElementById('loyaltyValidPoints');
    const historyBody = document.getElementById('loyaltyHistoryBody');
    const addPointsFormWrap = document.getElementById('addPointsFormWrap');
    const addPointsForm = document.getElementById('addPointsForm');
    const showAddPointsForm = document.getElementById('showAddPointsForm');

    function renderHistory(data) {
        customerNameEl.textContent = data.subscription.name + ' - ' + data.subscription.code;
        validPointsEl.textContent = data.subscription.valid_points;

        if (!data.transactions.length) {
            historyBody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-muted text-center">
                        No points history found.
                    </td>
                </tr>
            `;
            return;
        }

        historyBody.innerHTML = data.transactions.map(function (transaction) {
            return `
                <tr>
                    <td>${transaction.created_at || '-'}</td>
                    <td>${transaction.label}</td>
                    <td>
                        <span class="badge bg-success">${transaction.points}</span>
                    </td>
                    <td>${transaction.expires_at || 'Unlimited'}</td>
                    <td>
                        ${transaction.is_expired
                            ? '<span class="badge bg-danger">Expired</span>'
                            : '<span class="badge bg-success">Valid</span>'}
                    </td>
                    <td>${transaction.created_by || '-'}</td>
                </tr>
            `;
        }).join('');
    }

    function loadHistory() {
        if (!currentHistoryUrl) return;

        fetch(currentHistoryUrl, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            renderHistory(data);
        })
        .catch(error => {
            console.error('Unable to load loyalty history:', error);
        });
    }

    document.querySelectorAll('.open-loyalty-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            currentSubscriptionId = this.dataset.subscriptionId;
            currentHistoryUrl = this.dataset.historyUrl;
            currentStoreUrl = this.dataset.storeUrl;

            addPointsFormWrap.style.display = 'none';
            addPointsForm.reset();

            loadHistory();
            modal.show();
        });
    });

    showAddPointsForm.addEventListener('click', function () {
        addPointsFormWrap.style.display =
            addPointsFormWrap.style.display === 'none' ? 'block' : 'none';
    });

    addPointsForm.addEventListener('submit', function (event) {
        event.preventDefault();

        if (!currentStoreUrl) return;

        const formData = new FormData(addPointsForm);
        const csrfToken = addPointsForm.querySelector('input[name="_token"]').value;

        fetch(currentStoreUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData,
            credentials: 'same-origin'
        })
        .then(async response => {
            const data = await response.json();

            if (!response.ok) {
                console.error('Unable to save points:', data);
                return null;
            }

            return data;
        })
        .then(data => {
            if (!data) return;

            addPointsForm.reset();
            addPointsFormWrap.style.display = 'none';

            loadHistory();

            const rowButton = document.querySelector(`.open-loyalty-modal[data-subscription-id="${currentSubscriptionId}"]`);

            if (rowButton) {
                const row = rowButton.closest('tr');
                const badge = row.querySelector('.loyalty-points-badge');

                if (badge) {
                    badge.textContent = data.valid_points + ' points';
                }
            }
        })
        .catch(error => {
            console.error('Unable to save points:', error);
        });
    });
});
</script>
@endsection
