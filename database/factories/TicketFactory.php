<?php

namespace Database\Factories;

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
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketFactory extends Factory
{
    public function definition(): array
    {
        $ticket_number = now()->format('m') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT) . '-' . now()->format('Y');
        $department = Department::inRandomOrder()->first()->id;
        return [
            'status_id' => Status::where('label', '!=', 'Overdue')->inRandomOrder()->first()->id,
            'priority_id' => Priority::where('department_id', $department)->inRandomOrder()->first()->id,
            // 'assigned_user' => User::inRandomOrder()->first()->id,
            'ticket_number' => $ticket_number,
            'department' => $department
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Ticket $ticket){
            if($ticket->department === 1){

                TicketIT::create([
                    'ticket_id' => $ticket->id,
                    // 'type_of_tech_support'  => fake()->randomElement([
                    //     'Hardware',
                    //     'Software',
                    //     'Network',
                    //     'System Access',
                    //     'Email Issue',
                    //     'Printer Issue'
                    // ]),
                    'type_of_concern' => TypeOfConcern::inRandomOrder()->first()->id,
                    'name_of_area'          => fake()->randomElement([
                        'Emergency Room',
                        'OPD',
                        'Nursing Station',
                        'Pharmacy',
                        'Laboratory',
                        'Radiology',
                        'Admin Office',
                        'ICU',
                        'Surgery Room'
                    ]),
                    'remarks' => fake()->sentence(8),
                    'area_supervisor' => fake()->name()
                ]);

                Device::create([
                'ticket_id'  => $ticket->id,
                'device' => fake()->randomElement([
                    'Monitor',
                    'Printer',
                    'Keyboard',
                    'Mouse',
                    'CPU',
                    'Laptop',
                    'Scanner',
                    'UPS',
                    'Router',
                    'Switch'
                ]),
                'qty' => fake()->numberBetween(1, 10)
                ]);

            }else{
                $natureOfWork = NatureOfWork::inRandomOrder()->first();
                $other_nature_of_work = ($natureOfWork->label === 'Others') ? fake()->sentence(1) : null;
                
                TicketEngineering::create([
                    'ticket_id' => $ticket->id,
                    'nature_of_work' => $natureOfWork->id,
                    'scope_of_work' => fake()->sentence(2),
                    'other_nature_of_work' => $other_nature_of_work,
                    'name_of_area'          => fake()->randomElement([
                        'Emergency Room',
                        'OPD',
                        'Nursing Station',
                        'Pharmacy',
                        'Laboratory',
                        'Radiology',
                        'Admin Office',
                        'ICU',
                        'Surgery Room'
                    ]),
                    'area_supervisor' => fake()->name()
                ]);
            }

            // for status
            switch ($ticket->status_id) {
                case 1:
                    $this->generateDateActivity($ticket, 'Requested Date');
                    break;
                case 2:
                    $this->generateDateActivity($ticket, 'Requested Date');
                    $this->generateDateActivity($ticket, 'Assigned Date');
                    break;
                default:
                    $this->generateDateActivity($ticket, 'Requested Date');
                    $this->generateDateActivity($ticket, 'Assigned Date');
                    $this->generateDateActivity($ticket, 'Completed Date');
                    break;
            }

        });
    }

    public function generateDateActivity(Ticket $ticket, $category)
    {
        if($category == 'Requested Date'){
            $user_id = null;
            $requestor = fake()->name();
        }else{
            if($category == 'Completed Date'){
                $assignedUser = 
                TicketDateActivity::where('ticket_id', $ticket->id)
                ->where('date_category_id', 2)
                ->first();

                $user_id = $assignedUser->user_id;
            }else{
                $user_id = User::inRandomOrder()->where('department_id', $ticket->department)->first()->id;

                // Update assigned_user in tickets table
                $ticket->update(['assigned_user' => $user_id]);
            }
            $requestor = null;
        }

        TicketDateActivity::create([
            'ticket_id' => $ticket->id,
            'date_category_id' => DateCategory::where('category', $category)->first()->id,
            'user_id' => $user_id,
            'requestor' => $requestor,
            'requestorId' => 1 // id payload from request form
        ]);
    }
}

