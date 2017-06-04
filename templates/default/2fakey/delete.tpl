{% block subject %}
2FA Key Deleted: {{ twofactorkey.getDescription() }}
{% endblock %}

{% block body %}
Hi {{ user.getRealName() }},

A 2FA Key has been removed from your account on {{ sitename }}.

    Description: {{ twofactorkey.getDescription() }}

{% include 'footer.txt' %}
{% endblock %}
