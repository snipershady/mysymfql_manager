<?php

namespace App\Form;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SqlClientType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
                ->add('name', TextType::class, ['trim' => true, 'required' => true])
                ->add('host', TextType::class, ['trim' => true, 'required' => true])
                ->add('username', TextType::class, ['trim' => true, 'required' => true])
                ->add('password', TextType::class, ['trim' => true, 'required' => true])
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
        ]);
    }
}
