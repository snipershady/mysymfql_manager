<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
                ->add('oldPlainPassword', PasswordType::class, [
                    'label' => 'Old Password',
                    'required' => true,
                    'trim' => true,
                ])
                ->add('newPlainPassword', RepeatedType::class, [
                    'type' => PasswordType::class,
                    'invalid_message' => 'the passwords do not match',
                    'required' => true,
                    'trim' => true,
                    'first_options' => ['label' => 'Password'],
                    'second_options' => ['label' => 'Repeat Password'],
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password'),
                        new Length(min: 8, minMessage: 'Your password must be at least {{ limit }} characters long', max: 4096),
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
