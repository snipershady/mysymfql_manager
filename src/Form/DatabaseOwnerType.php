<?php

namespace App\Form;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DatabaseOwnerType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sqlClient', EntityType::class, [
                'class' => SqlClient::class,
                'choice_label' => fn (SqlClient $c): string => $c->getName().' — '.$c->getHost().':'.$c->getPort(),
                'choice_attr' => fn (SqlClient $c): array => ['data-name' => $c->getName()],
                'label' => 'Server MySQL',
                'placeholder' => '— Select a server —',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('dbName', TextType::class, [
                'label' => 'Database name',
                'attr' => ['class' => 'form-control font-monospace'],
            ])
            ->add('owner', EntityType::class, [
                'class' => AppUser::class,
                'choice_label' => 'username',
                'label' => 'Owner',
                'attr' => ['class' => 'form-select'],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DatabaseOwner::class,
        ]);
    }
}
