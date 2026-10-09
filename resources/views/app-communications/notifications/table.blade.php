<div class="card border-0 shadow-sm">

    <div class="card-header bg-white py-3">

        <h5 class="mb-0">
            Notification History
        </h5>

    </div>

    <div class="table-responsive">

        <table class="table table-hover align-middle mb-0">

            <thead class="table-light">

                <tr>

                    <th>
                        Notification
                    </th>

                    <th>
                        Target
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Sent
                    </th>

                    <th>
                        Views
                    </th>

                    <th style="width: 90px;">
                    </th>

                </tr>

            </thead>


            <tbody>

                @foreach(
                    $notifications
                    as $notification
                )

                    <tr>

                        {{-- NOTIFICATION --}}
                        <td>

                            <div class="fw-semibold">
                                {{
                                    $notification[
                                        'title'
                                    ]
                                }}
                            </div>

                            <div class="small text-muted">

                                #{{ $notification['id'] }}

                                ·

                                {{
                                    $notification[
                                        'target_count'
                                    ]
                                }}

                                recipient(s)

                            </div>

                        </td>


                        {{-- TARGET --}}
                        <td>

                            <span class="badge bg-light text-dark border">
                                {{
                                    $notification[
                                        'target'
                                    ]
                                }}
                            </span>

                        </td>


                        {{-- STATUS --}}
                        <td>

                            @php

                                $status =
                                    $notification[
                                        'status'
                                    ];

                                $statusClass =
                                    match ($status) {

                                        'sent' =>
                                            'bg-success',

                                        'sending' =>
                                            'bg-primary',

                                        'failed' =>
                                            'bg-danger',

                                        'draft' =>
                                            'bg-secondary',

                                        default =>
                                            'bg-secondary',
                                    };

                            @endphp

                            <span
                                class="
                                    badge
                                    {{ $statusClass }}
                                "
                            >

                                {{
                                    ucfirst(
                                        $status
                                    )
                                }}

                            </span>

                        </td>


                        {{-- SENT --}}
                        <td>

                            @if(
                                $notification[
                                    'sent_at'
                                ]
                            )

                                {{
                                    $notification[
                                        'sent_at'
                                    ]
                                }}

                            @else

                                <span class="text-muted">
                                    —
                                </span>

                            @endif

                        </td>


                        {{-- VIEWS --}}
                        <td>

                            @if(
                                $notification[
                                    'status'
                                ] === 'sent'
                            )

                                <div
                                    style="
                                        min-width: 140px;
                                    "
                                >

                                    <div
                                        class="
                                            d-flex
                                            justify-content-between
                                            small
                                            mb-1
                                        "
                                    >

                                        <span>

                                            {{
                                                $notification[
                                                    'viewed_count'
                                                ]
                                            }}

                                            /

                                            {{
                                                $notification[
                                                    'target_count'
                                                ]
                                            }}

                                        </span>

                                        <strong>

                                            {{
                                                number_format(
                                                    $notification[
                                                        'view_percentage'
                                                    ],
                                                    1
                                                )
                                            }}%

                                        </strong>

                                    </div>


                                    <div
                                        class="progress"
                                        style="height: 6px;"
                                    >

                                        <div
                                            class="
                                                progress-bar
                                                bg-success
                                            "
                                            style="
                                                width:
                                                {{
                                                    $notification[
                                                        'view_percentage'
                                                    ]
                                                }}%;
                                            "
                                        ></div>

                                    </div>

                                </div>

                            @else

                                <span class="text-muted">
                                    —
                                </span>

                            @endif

                        </td>


                        {{-- DETAILS --}}
                        <td class="text-end">

                            <a
                                href="{{
                                    route(
                                        'app-communications.notifications.show',
                                        $notification[
                                            'id'
                                        ]
                                    )
                                }}"
                                class="
                                    btn
                                    btn-sm
                                    btn-outline-secondary
                                "
                            >

                                <i class="fas fa-eye"></i>

                            </a>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>

    </div>

</div>