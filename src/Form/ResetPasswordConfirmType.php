<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * New password chosen from a password-reset link (same rules as registration).
 *
 * @extends AbstractType<mixed>
 */
final class ResetPasswordConfirmType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('newPlainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'The passwords do not match',
            'required' => true,
            // passwords are never trimmed
            'trim' => false,
            'first_options' => ['label' => 'Nuova password', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Ripeti password', 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => [
                new NotBlank(message: 'Please enter a password'),
                new Length(min: 8, max: 4096, minMessage: 'Your password must be at least {{ limit }} characters long'),
            ],
        ]);
    }
}
