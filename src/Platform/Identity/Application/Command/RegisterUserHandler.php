<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Command;

use App\Platform\Identity\Domain\Email;
use App\Platform\Identity\Domain\PasswordHasher;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Domain\UserIdGenerator;
use App\Platform\Identity\Domain\Users;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class RegisterUserHandler
{
    public function __construct(private Users $users, private PasswordHasher $hasher, private UserIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function __invoke(RegisterUser $command): User
    {
        $email = new Email($command->email);
        if (\strlen($command->password) < 10) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 10 caracteres.');
        } if (null !== $this->users->byEmail($email)) {
            throw new InvalidArgumentException('Ya existe una cuenta con este correo. Inicia sesión para continuar.');
        } $user = new User($this->ids->next(), $email, $this->hasher->hash($command->password), false, false, $this->clock->now());
        $this->users->save($user);

        return $user;
    }
}
