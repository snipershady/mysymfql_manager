<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<mixed>
 */
class ChangePasswordType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
                ->add('oldPlainPassword', PasswordType::class, [
                    'label' => 'Password attuale',
                    'required' => true,
                    'trim' => true,
                ])
                ->add('newPlainPassword', RepeatedType::class, [
                    'type' => PasswordType::class,
                    'invalid_message' => 'Le password non coincidono.',
                    'required' => true,
                    'trim' => true,
                    'first_options' => ['label' => 'Nuova password'],
                    'second_options' => ['label' => 'Ripeti password'],
                    'constraints' => [
                        new NotBlank(message: 'Inserisci una password.'),
                        new Length(min: 8, max: 4096, minMessage: 'La password deve avere almeno {{ limit }} caratteri.'),
                    ],
                ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // 'data_class' => AppUser::class,
            // enable/disable CSRF protection for this form
            'csrf_protection' => true,
            // the name of the hidden HTML field that stores the token
            'csrf_field_name' => '_token_register',
            // an arbitrary string used to generate the value of the token
            // using a different string for each form improves its security
            'csrf_token_id' => 'regiser_item',
        ]);
    }
}
