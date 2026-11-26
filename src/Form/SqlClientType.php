<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<SqlClient>
 */
class SqlClientType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
                ->add('name', TextType::class, ['trim' => true, 'required' => true])
                ->add('host', TextType::class, ['trim' => true, 'required' => true])
                ->add('username', TextType::class, ['trim' => true, 'required' => true])
                // Never rendered back (PasswordType, always_empty) and never trimmed. Not mapped: the
                // controller sets it only when a value is typed, so editing keeps the stored password.
                ->add('password', PasswordType::class, [
                    'mapped' => false,
                    'required' => $options['require_password'],
                    'trim' => false,
                    'always_empty' => true,
                    'help' => $options['require_password'] ? null : 'Lascia vuoto per mantenere la password attuale.',
                    'attr' => ['autocomplete' => 'new-password'],
                    'constraints' => $options['require_password'] ? [new NotBlank(message: 'Please enter the password')] : [],
                ])
                ->add('port', IntegerType::class, ['trim' => true])
                ->add('owner', EntityType::class, [
                    'class' => AppUser::class,
                    'choice_label' => 'username',
                    'multiple' => true,
                    'required' => true,
                ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SqlClient::class,
            // false on edit: an empty password field keeps the stored one
            'require_password' => true,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
    }
}
