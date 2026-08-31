<?php

namespace App\Form;

use App\Entity\AppUser;
use App\Enum\RoleEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AppUserAdminType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $roleChoices = [];
        foreach (RoleEnum::cases() as $role) {
            $roleChoices[$role->label()] = $role->value;
        }

        $builder
            ->add('username', TextType::class, ['required' => true, 'trim' => true])
            ->add('email', EmailType::class, ['required' => true, 'trim' => true])
            ->add('roles', ChoiceType::class, [
                'required' => true,
                'multiple' => true,
                'expanded' => true,
                'choices' => $roleChoices,
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'required' => false,
                'trim' => true,
                'attr' => ['placeholder' => 'Leave blank to keep unchanged'],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AppUser::class]);
    }
}
