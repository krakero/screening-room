<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create
        {--name= : The user\'s name}
        {--email= : The user\'s email address}
        {--password= : The user\'s password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a user account';

    public function handle(CreateNewUser $creator): int
    {
        $name = $this->option('name') ?? text(label: 'Name', required: true);
        $email = $this->option('email') ?? text(label: 'Email address', required: true);
        $password = $this->option('password') ?? password(label: 'Password', required: true);
        $passwordConfirmation = $this->option('password') ?? password(label: 'Confirm password', required: true);

        try {
            $user = $creator->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ]);
        } catch (ValidationException $e) {
            foreach (Arr::flatten($e->errors()) as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("User [{$user->email}] created.");

        return self::SUCCESS;
    }
}
