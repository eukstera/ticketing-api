<?php

use App\Http\Controllers\AccomplishmentReportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\TicketingController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth Login
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// get all designations
Route::get('/designations', [UserController::class, 'getDesignations']);
// save designations
Route::post('/designations', [UserController::class, 'storeDesignation']);

// Save Ticket
Route::post('/saveTicketIT', [TicketingController::class, 'saveTicketIT']);
Route::post('/saveTicketEngineering', [TicketingController::class, 'saveTicketEngineering']);

// fetched ticket requests saved by requestor
Route::get('/getTicketRequests', [TicketingController::class, 'getTicketRequests']);

Route::middleware('auth:sanctum')->group(function(){

    // lookup lists for ticket forms
    Route::get('/lookups/concern-types', [LookupController::class, 'concernTypes']);
    Route::get('/lookups/nature-of-work', [LookupController::class, 'natureOfWork']);
    Route::get('/lookups/departments', [LookupController::class, 'departments']);

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

    // ticket detail
    Route::get('/tickets/{ticket}', [TicketingController::class, 'show']);

    // fetch all comments
    Route::get('/tickets/{ticket}/comments', [CommentController::class, 'index']);

    // update comments
    Route::patch('/tickets/comments/{comment}', [CommentController::class, 'updateComment']);

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
    
});