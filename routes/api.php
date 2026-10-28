<?php

use App\Http\Controllers\AccomplishmentReportController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ConcernTypeController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DesignationController;
use App\Http\Controllers\Admin\NatureOfWorkController;
use App\Http\Controllers\Admin\PriorityController;
use App\Http\Controllers\Admin\ReportSignatoryController as AdminReportSignatoryController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ConsolidatedReportController;
use App\Http\Controllers\FalseAlarmController;
use App\Http\Controllers\JobOrderController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\NewTicketController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicConfirmationController;
use App\Http\Controllers\ReopenController;
use App\Http\Controllers\ReportSignatoryController;
use App\Http\Controllers\TicketConfirmationController;
use App\Http\Controllers\TicketingController;
use App\Http\Controllers\TrackConfirmationController;
use App\Http\Controllers\TrackTicketController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth Login (throttled to 5/min per email + IP)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// get all designations
Route::get('/designations', [UserController::class, 'getDesignations']);

// Save Ticket
Route::post('/saveTicketIT', [TicketingController::class, 'saveTicketIT']);
Route::post('/saveTicketEngineering', [TicketingController::class, 'saveTicketEngineering']);

// fetched ticket requests saved by requestor
Route::get('/getTicketRequests', [TicketingController::class, 'getTicketRequests']);

// public ticket tracking by ticket number and requester name
Route::post('/track', [TrackTicketController::class, 'show'])->middleware('throttle:track');

// requester confirms or disputes a completed ticket, then answers the satisfaction survey
Route::middleware('throttle:confirmation')->group(function () {
    Route::get('/confirm/{token}', [PublicConfirmationController::class, 'show']);
    Route::post('/confirm/{token}/confirm', [PublicConfirmationController::class, 'confirm']);
    Route::post('/confirm/{token}/dispute', [PublicConfirmationController::class, 'dispute']);
    Route::post('/confirm/{token}/survey', [PublicConfirmationController::class, 'survey']);

    Route::post('/track/confirm', [TrackConfirmationController::class, 'confirm']);
    Route::post('/track/dispute', [TrackConfirmationController::class, 'dispute']);
    Route::post('/track/survey', [TrackConfirmationController::class, 'survey']);
});

Route::middleware('auth:sanctum')->group(function () {

    // current session / account
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // admin only: create accounts and designations
    Route::middleware('can:admin')->group(function () {
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AuthController::class, 'storeUser']);
        Route::get('/admin/users/{user}', [AdminUserController::class, 'show']);
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update']);
        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy']);
    });

    Route::middleware('throttle:profile')->group(function () {
        Route::patch('/me', [ProfileController::class, 'update']);
        Route::patch('/me/password', [ProfileController::class, 'updatePassword']);
    });
    Route::post('/designations', [UserController::class, 'storeDesignation'])->middleware('can:admin');

    // lookup lists for ticket forms
    Route::get('/lookups/concern-types', [LookupController::class, 'concernTypes']);
    Route::get('/lookups/nature-of-work', [LookupController::class, 'natureOfWork']);
    Route::get('/lookups/departments', [LookupController::class, 'departments']);
    Route::get('/roles', [LookupController::class, 'roles']);

    // fetch all data for dashboard display
    Route::get('/dashboard', [TicketingController::class, 'getDashboardStats']);

    // get all tickets per department
    Route::get('/getDepartmentTickets', [TicketingController::class, 'getDepartmentTickets']);

    // get my tickets
    Route::get('/getMyTickets', [TicketingController::class, 'getMyTickets']);

    // get all employees for assign of ticket
    Route::get('/employees', [UserController::class, 'index']);

    // save custom IT request ticket
    Route::post('/saveRequestTicketIT', [TicketingController::class, 'saveRequestTicketIT']);

    // assign ticket
    Route::post('/tickets/{ticket}/assign', [TicketingController::class, 'assignTicket']);

    // new tickets after an id cursor for the alarm poll (must precede /tickets/{ticket})
    Route::get('/tickets/new', [NewTicketController::class, 'index']);

    // reject an untaken request as a false alarm
    Route::post('/tickets/{ticket}/false-alarm', FalseAlarmController::class);

    // filled IT job order request + satisfaction survey workbook (.xlsx)
    Route::get('/tickets/{ticket}/job-order', JobOrderController::class);

    // staff side of the completion confirmation
    Route::post('/tickets/{ticket}/confirmation/close', [TicketConfirmationController::class, 'close']);
    Route::post('/tickets/{ticket}/confirmation/resend', [TicketConfirmationController::class, 'resend']);

    // reopen a false-alarm ticket (Department Heads only)
    Route::post('/tickets/{ticket}/reopen', ReopenController::class);

    // ticket detail
    Route::get('/tickets/{ticket}', [TicketingController::class, 'show']);

    // fetch all comments
    Route::get('/tickets/{ticket}/comments', [CommentController::class, 'index']);

    // update comments
    Route::patch('/tickets/comments/{comment}', [CommentController::class, 'updateComment']);

    // delete comment (author or admin)
    Route::delete('/tickets/comments/{comment}', [CommentController::class, 'destroy']);

    // save comment
    Route::post('/tickets/{ticket}/comments', [CommentController::class, 'store']);

    // mark ticket as complete
    Route::post('/tickets/{ticket}/complete', [TicketingController::class, 'markAsComplete']);

    // get all priority based in department
    Route::get('/priority', [TicketingController::class, 'getPriority']);

    // calculation for overdue tickets based in requested date > current date, add SLA per priority for calculation
    Route::get('/overdueTickets', [TicketingController::class, 'overdueTickets']);

    // Generate accomplishment report
    Route::get('/reports/accomplishment', [AccomplishmentReportController::class, 'generate']);

    // consolidated report of the caller's department (Encoders and Department Heads)
    Route::get('/reports/consolidated', [ConsolidatedReportController::class, 'index']);
    Route::get('/reports/consolidated/options', [ConsolidatedReportController::class, 'options']);
    Route::get('/reports/consolidated/export', [ConsolidatedReportController::class, 'export']);

    // active signatories for the report download dropdown
    Route::get('/report-signatories', [ReportSignatoryController::class, 'index']);

    // admin lookup CRUD (admin gate is enforced in the controllers); DELETE is a soft deactivate
    Route::prefix('admin')->group(function () {
        $resource = fn (string $path, string $controller, array $only) => Route::apiResource($path, $controller)
            ->only($only)
            ->parameters([$path => 'id'])
            ->where(['id' => '[0-9]+']);

        $resource('departments', DepartmentController::class, ['index', 'store', 'update', 'destroy']);
        $resource('roles', RoleController::class, ['index', 'store', 'update', 'destroy']);
        $resource('designations', DesignationController::class, ['index', 'update', 'destroy']);
        $resource('priorities', PriorityController::class, ['index', 'store', 'update', 'destroy']);
        $resource('concern-types', ConcernTypeController::class, ['index', 'store', 'update', 'destroy']);
        $resource('nature-of-work', NatureOfWorkController::class, ['index', 'store', 'update', 'destroy']);
        $resource('report-signatories', AdminReportSignatoryController::class, ['index', 'store', 'update', 'destroy']);
    });

});
