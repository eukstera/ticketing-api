<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\DateCategory;
use App\Models\Department;
use App\Models\Device;
use App\Models\NatureOfWork;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\TicketEngineering;
use App\Models\TicketIT;
use App\Models\TypeOfConcern;
use App\Services\TicketNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TicketingController extends Controller
{
    use ApiResponseTrait;

    public function saveTicketIT(Request $request, TicketNumberGenerator $ticketNumberGenerator)
    {
        $validator = Validator::make($request->all(), [
            'service_department' => 'required',
            'area_supervisor' => 'required',
            'type_of_concern' => 'required',
            'devices' => 'sometimes|array',
            'devices.*.type' => 'required|string',
            'devices.*.quantity' => 'required|integer|min:1',
            'name_of_area' => 'required',
            'remarks' => 'required',
            'priority' => 'required',
            'requestor' => 'required',
            'requestor_meta.id' => 'required'
        ]);

        $validator->sometimes('devices', 'required', function ($input) {
            return $input->type_of_concern === 'Hardware';
        });

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 200);
        }

        $ticket = Ticket::create([
            'status_id' => 1, // Open
            'priority_id' => $request->priority,
            'ticket_number' => $ticketNumberGenerator->generate($request->service_department),
            'department' => $request->service_department
        ]);

        $typeOfConcern = TypeOfConcern::firstOrCreate([
            'label' => $request->type_of_concern
        ]);

        TicketIT::create([
            'ticket_id' => $ticket->id,
            'type_of_concern' => $typeOfConcern->id,
            'name_of_area' => $request->name_of_area,
            'remarks' => $request->remarks,
            'area_supervisor' => $request->area_supervisor
        ]);

        $devices = array_map(fn($device) => [
            'ticket_id'  => $ticket->id,
            'device'     => $device['type'],
            'qty'        => $device['quantity'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $request->devices);

        Device::insert($devices);

        TicketDateActivity::create([
            'ticket_id' => $ticket->id,
            'date_category_id' => 1, // Requested Date
            'requestor' => $request->requestor,
            'requestorId' => $request->requestor_meta['id']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket saved',
            'result' => [
                'ticket' => $ticket
            ]
        ], 201);

    }



    // for saving of sir Leandro
    public function saveRequestTicketIT(Request $request, TicketNumberGenerator $ticketNumberGenerator)
    {
        $validator = Validator::make($request->all(), [
            'service_department' => 'required',
            'area_supervisor' => 'required',
            'type_of_concern' => 'required',
            'devices' => 'sometimes|array',
            'devices.*.type' => 'required|string',
            'devices.*.quantity' => 'required|integer|min:1',
            'name_of_area' => 'required',
            'remarks' => 'required',
            'assigned_user' => 'required',
            'priority' => 'required',
            'requestor' => 'required'
        ]);

        $validator->sometimes('devices', 'required', function ($input) {
            return $input->type_of_concern === 'Hardware';
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 200);
        }

        $ticket = Ticket::create([
            'status_id' => 2, // Assigned
            'priority_id' => $request->priority,
            'assigned_user' => $request->assigned_user,
            'ticket_number' => $ticketNumberGenerator->generate($request->service_department),
            'department' => $request->service_department
        ]);

        $typeOfConcern = TypeOfConcern::firstOrCreate([
            'label' => $request->type_of_concern
        ]);

        TicketIT::create([
            'ticket_id' => $ticket->id,
            'type_of_concern' => $typeOfConcern->id,
            'name_of_area' => $request->name_of_area,
            'remarks' => $request->remarks,
            'area_supervisor' => $request->area_supervisor
        ]);

        $devices = array_map(fn($device) => [
            'ticket_id'  => $ticket->id,
            'device'     => $device['type'],
            'qty'        => $device['quantity'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $request->devices);

        Device::insert($devices);

        TicketDateActivity::create([
            'ticket_id' => $ticket->id,
            'date_category_id' => 1, // Requested Date
            'user_id' => $request->user()->id, // Sir Drew for direct management of tech suport 
            'requestor' => $request->requestor
        ]);

        // Assigned to
        TicketDateActivity::create([
            'ticket_id' => $ticket->id,
            'date_category_id' => 2, // Assigned Date
            'user_id' => $request->assigned_user, // Sir Drew for direct management of tech suport 
            'requestor' => null
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket saved',
            'result' => [
                'ticket' => $ticket
            ]
        ], 201);
    }


    public function saveTicketEngineering(Request $request, TicketNumberGenerator $ticketNumberGenerator)
    {
        $validator = Validator::make($request->all(), [
            'service_department' => 'required',
            'area_supervisor' => 'nullable',
            'nature_of_work' => 'required',
            'scope_of_work' => 'required', // Remarks
            'other_nature_of_work' => 'sometimes', // required if nature_of_work = Others
            'name_of_area' => 'required',
            'priority' => 'required',
            'requestor' => 'required',
            'requestor_meta.id' => 'required'
        ]);

        $validator->sometimes('other_nature_of_work', 'required', function ($input) {
            return $input->service_department === 'Engineering' && $input->nature_of_work === 'Others';
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 200);
        }

        $ticket = Ticket::create([
            'status_id' => 1, // Open
            'priority_id' => $request->priority,
            'ticket_number' => $ticketNumberGenerator->generate($request->service_department),
            'department' => $request->service_department
        ]);

        $natureOfWork = NatureOfWork::firstOrCreate([
            'label' => $request->nature_of_work
        ]);

        TicketEngineering::create([
            'ticket_id' => $ticket->id,
            'nature_of_work' => $natureOfWork->id,
            'scope_of_work' => $request->scope_of_work, // remarks
            'other_nature_of_work' => $request->other_nature_of_work,
            'name_of_area' => $request->name_of_area,
            'area_supervisor' => $request->area_supervisor
        ]);

        TicketDateActivity::create([
            'ticket_id' => $ticket->id,
            'date_category_id' => 1, // Requested Date
            'requestor' => $request->requestor,
            'requestorId' => $request->requestor_meta['id']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket saved',
            'result' => [
                'ticket' => $ticket
            ]
        ], 201);

    }



    public function overdueTickets(Request $request)
    {
        $overdue = Ticket::where('department', $request->user()->department_id)
            ->whereHas('ticketStatus', fn ($query) => $query->where('label', 'Overdue'))
            ->count();

        return response()->json([
            'ticket' => $overdue
        ]);
    }


    private function getStatusCountsForDepartment(Request $request): array
    {
        $department_id = $request->user()->department_id;
        $counts = Ticket::join('status', 'status.id', 'tickets.status_id')
            ->where('tickets.department', $department_id)
            ->selectRaw('status.label as status, COUNT(DISTINCT tickets.id) as total')
            ->groupBy('status.label')
            ->pluck('total', 'status');

        $statusCounts = [
            'All' => Ticket::where('department', $department_id)->count(),
            'Open' => 0,
            'In-progress' => 0,
            'Completed' => 0,
            'Overdue' => 0,
        ];

        foreach ($counts as $label => $total) {
            $statusCounts[$label] = $total;
        }

        return $statusCounts;
    }

    private function getStatusCountsForUser(Request $request): array
    {
        $department_id = $request->user()->department_id;
        $userId = $request->user()->id;
        $counts = Ticket::join('status', 'status.id', 'tickets.status_id')
            ->join('ticket_date_activities', function ($join) use ($userId) {
                $join->on('ticket_date_activities.ticket_id', '=', 'tickets.id')
                    ->whereIn('ticket_date_activities.date_category_id', [1, 2])
                    ->where('ticket_date_activities.user_id', $userId);
            })
            ->where('tickets.department', $department_id)
            ->selectRaw('status.label as status, COUNT(DISTINCT tickets.id) as total')
            ->groupBy('status.label')
            ->pluck('total', 'status');

        $statusCounts = ['All' => 0, 'Open' => 0, 'In-progress' => 0, 'Completed' => 0, 'Overdue' => 0];
        foreach ($counts as $label => $total) {
            $statusCounts[$label] = $total;
            $statusCounts['All'] += $total;
        }

        return $statusCounts;
    }

    private function getPriorityCountsForDepartment(Request $request): array
    {
        $department_id = $request->user()->department_id;
        $counts = Ticket::join('priority', 'priority.id', 'tickets.priority_id')
            ->where('tickets.department', $department_id)
            ->selectRaw('priority.level as priority, count(*) as total')
            ->groupBy('priority.level')
            ->pluck('total', 'priority');

        $priorityCounts = Priority::where('department_id', $department_id)
            ->get()
            ->mapWithKeys(fn($priority) => [$priority->level => 0])
            ->toArray();

        foreach ($counts as $level => $total) {
            $priorityCounts[$level] = $total;
        }

        return $priorityCounts;
    }

    private function getPriorityCountsForUser(Request $request): array
    {
        $department_id = $request->user()->department_id;
        $userId = $request->user()->id;

        $counts = Ticket::join('priority', 'priority.id', 'tickets.priority_id')
            ->join('ticket_date_activities', function ($join) use ($userId) {
                $join->on('ticket_date_activities.ticket_id', '=', 'tickets.id')
                    ->whereIn('ticket_date_activities.date_category_id', [1, 2])
                    ->where('ticket_date_activities.user_id', $userId);
            })
            ->where('tickets.department', $department_id)
            ->selectRaw('priority.level as priority, COUNT(DISTINCT tickets.id) as total')
            ->groupBy('priority.level')
            ->pluck('total', 'priority');

        $priorityCounts = Priority::where('department_id', $department_id)
            ->get()
            ->mapWithKeys(fn($priority) => [$priority->level => 0])
            ->toArray();

        foreach ($counts as $level => $total) {
            $priorityCounts[$level] = $total;
        }

        return $priorityCounts;
    }

    private function getPrioritySlaForDepartment(Request $request): array
    {
        $department_id = $request->user()->department_id;
        return Priority::where('department_id', $department_id)
            ->get()
            ->mapWithKeys(fn($priority) => [$priority->level => $priority->SLA])
            ->toArray();
    }


    public function baseTicket(Request $request, $userId = null)
    {
        $department_id = $request->user()->department_id;

        $query = Ticket::
            select(
                'tickets.id as id',
                'ticket_number',
                'name_of_area',
                'area_supervisor',
                'status.label as status',
                'priority.level as priority',
                'priority.SLA as priority_sla',
                'departments.department'
            )
            ->when($department_id == 1, function($q){
                $q->join('tickets_it', function ($join) {
                    $join->on('tickets.id', 'tickets_it.ticket_id');
                })
                ->join('type_of_concern', function ($join) {
                    $join->on('type_of_concern.id', 'tickets_it.type_of_concern');
                })
                ->addSelect(
                    'type_of_concern.label as type_of_concern', 
                    'type_of_tech_support', 
                    'remarks'
                    )
                ->groupBy('tickets_it.id');
            }, function($q){
                $q->join('tickets_engineering', function ($join) {
                    $join->on('tickets.id', 'tickets_engineering.ticket_id');
                })
                ->join('nature_of_work', function ($join) {
                    $join->on('nature_of_work.id', 'tickets_engineering.nature_of_work');
                })
                ->addSelect(
                    'scope_of_work', 
                    DB::raw("CASE WHEN nature_of_work.label = 'Others' THEN other_nature_of_work ELSE nature_of_work.label END as nature_of_work")
                    )
                ->groupBy('tickets_engineering.id');
            })
            ->join('priority', function ($join) {
                $join->on('priority.id', 'tickets.priority_id');
            })
            ->join('status', function ($join) {
                $join->on('status.id', 'tickets.status_id');
            })
            ->join('departments', function ($join) {
                $join->on('departments.id', 'tickets.department');
            })
            ->leftJoin('users', function ($join) {
                $join->on('users.id', 'tickets.assigned_user');
            })
            ->where('tickets.department', $department_id)
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status.label', $request->status);
            })
            ->when($request->filled('priority'), function ($q) use ($request) {
                $q->where('priority.level', $request->priority);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where('ticket_number', 'like', "%{$search}%")
                        // ->orWhere('type_of_concern.label', 'like', "%{$search}%")
                        ->orWhere('name_of_area', 'like', "%{$search}%");
                });
            })
            ->when($userId, function ($q) use ($userId) {
                $q->leftJoin('ticket_date_activities', function ($join) use ($userId) {
                    $join->on('ticket_date_activities.ticket_id', 'tickets.id');
                })
                ->whereRaw("(date_category_id IN (1, 2)) AND user_id = $userId");
            })
            ->with(['dateActivities.dateCategory', 'dateActivities.user', 'requestedActivity', 'assignedActivity', 'completedActivity', 'devices'])
            ->withCount('comments')
            // ->addSelect([
            //     'comments_count' => Comment::selectRaw('count(*)')
            //         ->whereColumn('comments.ticket_id', 'tickets.id')
            // ])
            ->orderBy('tickets.created_at', 'desc');

        $ticket = $query->paginate(10);

        $data = [
            'result' => [
                'ticket' => $ticket, // now paginated from the FILTERED query
                'priority_sla' => $this->getPrioritySlaForDepartment($request)
            ]
        ];

        return $data;
    }

    public function getDepartmentTickets(Request $request)
    {
        $response = $this->baseTicket($request);
        $response['message'] = 'Department tickets retreived';
        $response['result']['status_counts'] = $this->getStatusCountsForDepartment($request);
        $response['result']['priority_counts'] = $this->getPriorityCountsForDepartment($request);

        return response()->json($response, 200);
    }

    public function getMyTickets(Request $request)
    {
        $userId = $request->user()->id;
        $response = $this->baseTicket($request, $userId);
        $response['message'] = 'My tickets retreived';
        $response['result']['status_counts'] = $this->getStatusCountsForUser($request);
        $response['result']['priority_counts'] = $this->getPriorityCountsForUser($request);

        return response()->json($response, 200);
    }


    public function getTicketRequests(Request $request)
    {
        $baseQuery = Ticket::select(
                'tickets.id as id',
                'ticket_number',
                'name_of_area',
                'area_supervisor',
                'status.label as status',
                'priority.level as priority',
                'priority.SLA as priority_sla',
                'departments.department'
            )
            ->join('priority', function ($join) {
                $join->on('priority.id', 'tickets.priority_id');
            })
            ->join('status', function ($join) {
                $join->on('status.id', 'tickets.status_id');
            })
            ->join('departments', function ($join) {
                $join->on('departments.id', 'tickets.department');
            })
            ->leftJoin('users', function ($join) {
                $join->on('users.id', 'tickets.assigned_user');
            })
            ->leftJoin('ticket_date_activities', function ($join) {
                $join->on('ticket_date_activities.ticket_id', 'tickets.id');
            })
            ->where('requestorId', $request->requestorId)
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status.label', $request->status);
            })
            ->when($request->filled('priority'), function ($q) use ($request) {
                $q->where('priority.level', $request->priority);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('name_of_area', 'like', "%{$search}%");
                });
            })
            ->with(['dateActivities.dateCategory', 'dateActivities.user', 'requestedActivity', 'assignedActivity', 'completedActivity', 'devices'])
            ->withCount('comments')
            ->orderBy('tickets.created_at', 'desc');

        $ticketsIT =
        (clone $baseQuery)->join('tickets_it', function ($join) {
            $join->on('tickets.id', 'tickets_it.ticket_id');
        })
        ->join('type_of_concern', function ($join) {
            $join->on('type_of_concern.id', 'tickets_it.type_of_concern');
        })
        ->where('tickets.department', 1)
        ->addSelect(
            'type_of_concern.label as type_of_concern',
            'type_of_tech_support',
            'remarks'
        )
        ->groupBy('tickets_it.id')
        ->get();

        $ticketsEngineering =
        (clone $baseQuery)->join('tickets_engineering', function ($join) {
            $join->on('tickets.id', 'tickets_engineering.ticket_id');
        })
        ->join('nature_of_work', function ($join) {
            $join->on('nature_of_work.id', 'tickets_engineering.nature_of_work');
        })
        ->where('tickets.department', 2)
        ->addSelect(
            'scope_of_work',
            DB::raw("CASE WHEN nature_of_work.label = 'Others' THEN other_nature_of_work ELSE nature_of_work.label END as nature_of_work")
        )
        ->groupBy('tickets_engineering.id')
        ->get();

        return response()->json([
            'message' => 'Ticket requests retreived successfully',
            'result' => [
                'ticketsIT' => $ticketsIT,
                'ticketsEngineering' => $ticketsEngineering
            ]
        ], 201);
    }

    public function assignTicket(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        TicketDateActivity::create([
            'ticket_id'        => $ticket->id,
            'date_category_id' => DateCategory::where('category', 'Assigned Date')->first()->id,
            'user_id'          => $validated['user_id'],
            'status'           => 1
        ]);

        $ticket->update(['status_id' => 2]);

        return response()->json([
            'message' => 'Ticket assigned successfully',
            'result' => [
                'ticket' => $ticket
            ]
        ], 201);
    }


    public function markAsComplete(Ticket $ticket)
    {
        $completedCategory = DateCategory::where('category', 'Completed Date')->first();

        $assignedUser = 
        TicketDateActivity::where('ticket_id', $ticket->id)
        ->where('date_category_id', 2)
        ->first();

        TicketDateActivity::create([
            'ticket_id'        => $ticket->id,
            'date_category_id' => $completedCategory->id,
            'user_id'          => $assignedUser->user_id, // Mark as completed by the assigned user
            'status'           => 1
        ]);

        $completedStatus = Status::where('label', 'Completed')->first();

        $ticket->update([
            'status_id' => $completedStatus->id
        ]);

        return response()->json([
            'message' => 'Ticket marked as complete',
            'result' => [
                'ticket' => $ticket
            ]
        ], 201);
    }




    public function getDashboardStats(Request $request)
    {
        $department = $request->user()->department_id ?? 1; // TODO: confirm actual column on users table

        // $priorityFilterID = $request->query('priority'); // now a dynamic level string, e.g. 'Urgent'
        $month = $request->query('date'); // 'YYYY-MM'

        $anchor = $month ? Carbon::createFromFormat('Y-m', $month) : Carbon::now();
        $start = $anchor->copy()->startOfMonth();
        $end = $anchor->copy()->endOfMonth();

        $base = Ticket::join('status', 'status.id', 'tickets.status_id')
            ->join('priority', 'priority.id', 'tickets.priority_id')
            ->where('tickets.department', $department)
            ->whereBetween('tickets.created_at', [$start, $end]);
            // ->when($priorityFilterID, fn($q) => $q->where('priority.id', $priorityFilterID));

        $overview = [
            'open' => (clone $base)->where('status.label', 'Open')->count(),
            'in_progress' => (clone $base)->where('status.label', 'In-progress')->count(),
            'completed' => (clone $base)->where('status.label', 'Completed')->count(),
            'overdue' => (clone $base)->where('status.label', 'Overdue')->count(),
        ];

        // Separate base WITHOUT the priority filter, so the breakdown always shows
        // all priorities for the department even when the user has filtered the
        // overview/table by a single priority.
        $statsBase = Ticket::join('status', 'status.id', 'tickets.status_id')
            ->join('priority', 'priority.id', 'tickets.priority_id')
            ->where('tickets.department', $department)
            ->whereBetween('tickets.created_at', [$start, $end]);

        $priorityStats = Priority::where('department_id', $department)
            ->get()
            ->map(function ($priority) use ($statsBase) {
                $count = (clone $statsBase)
                    ->where('priority.id', $priority->id)
                    ->count();

                return [
                    'priority_id' => $priority->id,
                    'level' => $priority->level,
                    'count' => $count,
                ];
            })
            ->values();

        $today = Carbon::today();

        $todayBase = Ticket::join('status', 'status.id', 'tickets.status_id')
            ->where('tickets.department', $department)
            ->whereDate('tickets.created_at', $today);

        $todayTotal = (clone $todayBase)->count();
        $todayCompleted = (clone $todayBase)->where('status.label', 'Completed')->count();
        $todayPercent = $todayTotal > 0 ? (int) round(($todayCompleted / $todayTotal) * 100) : 0;

        $topPerformers = TicketDateActivity::join('date_categories', 'date_categories.id', 'ticket_date_activities.date_category_id')
            ->join('users', 'users.id', 'ticket_date_activities.user_id')
            ->join('tickets', 'tickets.id', 'ticket_date_activities.ticket_id')
            ->where('tickets.department', $department)
            ->where('date_categories.category', 'Completed Date')
            ->whereBetween('ticket_date_activities.created_at', [$start, $end])
            ->selectRaw('users.id, users.first_name, users.last_name, count(*) as completed_count')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('completed_count')
            ->limit(5)
            ->get()
            ->values()
            ->map(function ($row, $index) use ($department) {
                $inProgress = Ticket::join('status', 'status.id', 'tickets.status_id')
                    ->where('tickets.department', $department)
                    ->where('tickets.assigned_user', $row->id)
                    ->where('status.label', 'In-progress')
                    ->count();

                return [
                    'rank' => $index + 1,
                    'user_id' => $row->id,
                    'name' => trim("{$row->first_name} {$row->last_name}"),
                    'in_progress' => $inProgress,
                    'completed' => (int) $row->completed_count,
                ];
            });

        return response()->json([
            'message' => 'Dashboard stats retrieved',
            'result' => [
                'overview' => $overview,
                'priority_stats' => $priorityStats,
                'todays_ticket' => [
                    'total' => $todayTotal,
                    'completed' => $todayCompleted,
                    'percent_completed' => $todayPercent,
                ],
                'top_performers' => $topPerformers,
            ],
        ], 200);
    }

    
    public function getPriority(Request $request)
    {
        $query = Priority::query();

        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        return response()->json($query->get());
    }

}
